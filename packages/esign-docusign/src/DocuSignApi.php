<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign;

use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Talks to DocuSign: JWT Grant for the access token, /oauth/userinfo for the account's base URI,
 * and the eSignature REST API v2.1 under {base_uri}/restapi/v2.1/accounts/{accountId}.
 *
 * JWT Grant (https://developers.docusign.com/platform/auth/jwt-get-token/):
 * - the JWT is RS256-signed with the integration's private key, claims iss (integration key),
 *   sub (user GUID), aud (the auth host without https://), iat, exp and scope "signature impersonation";
 * - it is POSTed as grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=<jwt>;
 * - the token lasts an hour and there is no refresh token. DocuSign advises getting a new one about
 *   15 minutes before expiry, so it is cached for expires_in - 900 seconds.
 *
 * /oauth/userinfo is rate limited per user and integration key and "should always be cached", so the
 * resolved account base path is cached too (in memory, and in the PSR-16 cache when there is one).
 *
 * Errors come back as HTTP 4xx with {"errorCode": "...", "message": "..."} (errorDetails).
 *
 * @internal used by DocuSignProvider
 */
final class DocuSignApi
{
    private const JWT_BEARER = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const SCOPE = 'signature impersonation';
    private const REFRESH_EARLY = 900;
    private const ACCOUNT_TTL = 86400;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    private ?string $accountBase = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly DocuSignCredentials $credentials,
        /** account-d.docusign.com (developer) or account.docusign.com (production), no scheme. */
        private readonly string $authHost,
        /** Skip /oauth/userinfo and use this base URI (e.g. https://demo.docusign.net). Needs an account ID. */
        ?string $baseUri = null,
        private readonly ?CacheInterface $cache = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        if ($baseUri !== null) {
            if ($credentials->accountId === null) {
                throw new EsignException('DocuSign base_uri needs account_id as well.');
            }
            $this->accountBase = self::accountBase($baseUri, $credentials->accountId);
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->json('GET', self::withQuery($path, $query));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->json('POST', $path, $body);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, array $query = []): array
    {
        return $this->json('PUT', self::withQuery($path, $query), $body);
    }

    /** @param array<string, string> $query */
    private static function withQuery(string $path, array $query): string
    {
        return $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /** The raw bytes of a file endpoint, such as documents/combined. */
    public function file(string $path): string
    {
        return (string) $this->call('GET', $path, null, 'application/pdf')->getBody();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, ?array $body = null): array
    {
        $response = $this->call($method, $path, $body, 'application/json');
        $raw = (string) $response->getBody();
        $json = $raw === '' ? [] : json_decode($raw, true);
        if (! is_array($json)) {
            throw new ProviderError('docusign', "{$method} {$this->safePath($path)} returned a non-JSON response.", $response->getStatusCode());
        }
        // Some responses (envelopeUpdateSummary, envelopeSummary) can carry errorDetails with a 2xx.
        // Treat that as a failure rather than assume the change happened.
        $details = $json['errorDetails'] ?? null;
        if (is_array($details) && is_string($details['errorCode'] ?? null) && $details['errorCode'] !== '' && $details['errorCode'] !== 'SUCCESS') {
            throw self::error("{$method} {$this->safePath($path)}", $response->getStatusCode(), $details);
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body, string $accept, bool $isRetry = false): ResponseInterface
    {
        $what = "{$method} {$this->safePath($path)}";
        $request = $this->requests->createRequest($method, $this->base() . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->token())
            ->withHeader('Accept', $accept);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        }

        $response = $this->send($request, $what);
        $status = $response->getStatusCode();

        if ($status === 401 && ! $isRetry) {
            // An expired or revoked token. Get a fresh one and try once more.
            $this->forgetToken();

            return $this->call($method, $path, $body, $accept, true);
        }
        if ($status >= 400) {
            $json = json_decode((string) $response->getBody(), true);

            throw self::error($what, $status, is_array($json) ? $json : []);
        }

        return $response;
    }

    /** @param array<mixed> $json an errorDetails object */
    private static function error(string $what, int $status, array $json): ProviderError
    {
        $code = is_string($json['errorCode'] ?? null) && $json['errorCode'] !== '' ? $json['errorCode'] : null;
        $message = is_string($json['message'] ?? null) && $json['message'] !== '' ? $json['message'] : 'Request failed';

        return new ProviderError('docusign', "{$what}: {$message}", $status, $code);
    }

    /** {base_uri}/restapi/v2.1/accounts/{accountId}, from /oauth/userinfo unless configured. */
    private function base(): string
    {
        if ($this->accountBase !== null) {
            return $this->accountBase;
        }

        $key = 'esign_docusign_account_' . sha1($this->authHost . '|' . $this->credentials->integrationKey . '|' . $this->credentials->userId . '|' . $this->credentials->accountId);
        $cached = $this->cache?->get($key);
        if (is_string($cached) && str_starts_with($cached, 'https://')) {
            return $this->accountBase = $cached;
        }

        $info = $this->userInfo();
        $accounts = is_array($info['accounts'] ?? null) ? array_values(array_filter($info['accounts'], 'is_array')) : [];
        $wanted = $this->credentials->accountId;
        $account = null;
        foreach ($accounts as $candidate) {
            $matches = $wanted === null ? ($candidate['is_default'] ?? false) === true : strcasecmp((string) ($candidate['account_id'] ?? ''), $wanted) === 0;
            if ($matches) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null || ! is_string($account['base_uri'] ?? null) || ! is_string($account['account_id'] ?? null)) {
            throw new ProviderError('docusign', $wanted === null
                ? 'DocuSign /oauth/userinfo lists no default account for this user. Set account_id.'
                : 'The DocuSign user has no access to the configured account_id.');
        }

        $this->accountBase = self::accountBase($account['base_uri'], $account['account_id']);
        $this->cache?->set($key, $this->accountBase, self::ACCOUNT_TTL);

        return $this->accountBase;
    }

    /** @return array<string, mixed> */
    private function userInfo(bool $isRetry = false): array
    {
        $request = $this->requests->createRequest('GET', "https://{$this->authHost}/oauth/userinfo")
            ->withHeader('Authorization', 'Bearer ' . $this->token())
            ->withHeader('Accept', 'application/json');
        $response = $this->send($request, 'userinfo');

        if ($response->getStatusCode() === 401 && ! $isRetry) {
            $this->forgetToken();

            return $this->userInfo(true);
        }
        $json = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || ! is_array($json)) {
            // Never echo the body: it holds the user's name and email.
            throw new ProviderError('docusign', 'GET /oauth/userinfo failed.', $response->getStatusCode());
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private static function accountBase(string $baseUri, string $accountId): string
    {
        $baseUri = rtrim($baseUri, '/');
        if (! str_starts_with($baseUri, 'https://')) {
            throw new ProviderError('docusign', 'DocuSign base_uri must start with https://.');
        }

        return $baseUri . '/restapi/v2.1/accounts/' . rawurlencode($accountId);
    }

    private function token(): string
    {
        $now = ($this->clock)();
        if ($this->token !== null && $now < $this->tokenExpiresAt) {
            return $this->token;
        }

        $cached = $this->cache?->get($this->tokenKey());
        if (is_array($cached) && is_string($cached['token'] ?? null) && (int) ($cached['expires_at'] ?? 0) > $now) {
            [$this->token, $this->tokenExpiresAt] = [$cached['token'], (int) $cached['expires_at']];

            return $this->token;
        }

        $form = http_build_query(['grant_type' => self::JWT_BEARER, 'assertion' => $this->jwt($now)], '', '&', PHP_QUERY_RFC1738);
        $request = $this->requests->createRequest('POST', "https://{$this->authHost}/oauth/token")
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($form));

        $response = $this->send($request, 'token');
        $json = json_decode((string) $response->getBody(), true);
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;

        if ($response->getStatusCode() >= 500) {
            throw new ProviderError('docusign', 'DocuSign authentication service is unavailable. Try again later.', $response->getStatusCode());
        }
        if (! is_string($token) || $token === '') {
            // The OAuth error code ("consent_required", "invalid_grant") is safe to show; the
            // assertion and the key never are.
            $code = is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : null;
            $hint = is_array($json) && is_string($json['error_description'] ?? null) && preg_match('/^[A-Za-z0-9_ .-]{1,100}$/', $json['error_description']) ? " ({$json['error_description']})" : '';
            $message = $code === 'consent_required'
                ? 'DocuSign JWT Grant needs consent: the user (or an admin) must grant this integration the "signature impersonation" scope once.'
                : "DocuSign authentication failed{$hint}. Check integration_key, user_id, private_key and environment.";

            throw new ProviderError('docusign', $message, $response->getStatusCode(), $code);
        }

        $lifetime = max(60, (int) ($json['expires_in'] ?? 3600) - self::REFRESH_EARLY);
        $this->token = $token;
        $this->tokenExpiresAt = $now + $lifetime;
        $this->cache?->set($this->tokenKey(), ['token' => $token, 'expires_at' => $this->tokenExpiresAt], $lifetime);

        return $token;
    }

    /** An RS256 JWT assertion for JWT Grant, signed with ext-openssl. */
    private function jwt(int $now): string
    {
        $segments = [
            self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'RS256'])),
            self::base64Url((string) json_encode([
                'iss' => $this->credentials->integrationKey,
                'sub' => $this->credentials->userId,
                'aud' => $this->authHost,
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => self::SCOPE,
            ], JSON_UNESCAPED_SLASHES)),
        ];

        $key = openssl_pkey_get_private($this->credentials->privateKey);
        if ($key === false) {
            while (openssl_error_string() !== false) {
                // drain OpenSSL's error queue; its messages are not useful to the caller
            }
            throw new EsignException('DocuSign private_key is not a readable PEM private key.');
        }
        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new EsignException('Could not sign the DocuSign JWT with private_key. It must be an RSA key.');
        }

        return implode('.', $segments) . '.' . self::base64Url($signature);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function forgetToken(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 0;
        $this->cache?->delete($this->tokenKey());
    }

    private function tokenKey(): string
    {
        return 'esign_docusign_token_' . sha1($this->authHost . '|' . $this->credentials->integrationKey . '|' . $this->credentials->userId);
    }

    /** The path without the query string, for error messages. */
    private function safePath(string $path): string
    {
        return explode('?', $path, 2)[0];
    }

    private function send(RequestInterface $request, string $what): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderError('docusign', "{$what}: could not reach DocuSign ({$e->getMessage()})", null, null, $e);
        }
    }
}
