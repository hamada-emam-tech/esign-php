<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe;

use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Talks to Acrobat Sign's REST API v6: the account's API access point, the access token,
 * and the error envelope.
 *
 * - Every account lives on one shard (api.na1, api.eu1, ...). Calls made anywhere else answer 403
 *   INVALID_API_ACCESS_POINT. GET /api/rest/v6/baseUris on the global host says which shard; the
 *   answer is cached (memory, and the PSR-16 cache when given) and looked up again once if a call
 *   reports INVALID_API_ACCESS_POINT.
 * - Errors are JSON. The developer guide shows {"code": "...", "message": "..."}; the OpenAPI
 *   examples show {"reason": "CODE: message", "status": 404}. Both are read.
 * - With OAuth refresh configured, a 401 gets a fresh access token and one retry. A fixed token
 *   (Integration Key) is never retried.
 *
 * Messages never contain a token or secret: Acrobat Sign's text is scrubbed of them before use.
 *
 * @internal used by AdobeProvider
 */
final class AdobeApi
{
    private const API_PATH = '/api/rest/v6';

    private ?string $accessPoint;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param string $discoveryHost the global host baseUris is called on, e.g. https://api.adobesign.com
     * @param string|null $configuredAccessPoint the account's apiAccessPoint, when known; skips discovery
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly AdobeCredentials $credentials,
        private readonly string $discoveryHost,
        private readonly ?string $configuredAccessPoint = null,
        private readonly ?CacheInterface $cache = null,
        ?\Closure $clock = null,
    ) {
        $this->accessPoint = $configuredAccessPoint === null ? null : self::normalise($configuredAccessPoint);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<mixed> the decoded JSON body */
    public function get(string $path): array
    {
        return $this->json($this->call('GET', $path), "GET {$path}");
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->json($this->call('POST', $path, self::encode($body), 'application/json'), "POST {$path}");
    }

    /**
     * PUT answers 200 with a JSON body on some endpoints and an empty one on others (/state).
     *
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    public function put(string $path, array $body): array
    {
        $response = $this->call('PUT', $path, self::encode($body), 'application/json');

        return trim((string) $response->getBody()) === '' ? [] : $this->json($response, "PUT {$path}");
    }

    /** DELETE answers 200 or 204 with no body that matters. */
    public function delete(string $path): void
    {
        $this->call('DELETE', $path);
    }

    /** The raw response body, for binary endpoints such as /combinedDocument. */
    public function download(string $path): string
    {
        return (string) $this->call('GET', $path, null, null, 'application/pdf, application/json')->getBody();
    }

    /**
     * POST /transientDocuments: a multipart upload with the parts File-Name, Mime-Type and File.
     * Built by hand so no multipart library is needed. One file per request, as the API requires.
     */
    public function uploadTransient(Document $document): string
    {
        $boundary = 'esign-' . bin2hex(random_bytes(12));
        $name = str_replace(['"', "\r", "\n"], ['', '', ''], $document->name);
        $body = '';
        foreach (['File-Name' => $name, 'Mime-Type' => $document->mimeType] as $part => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$part}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"File\"; filename=\"{$name}\"\r\n"
            . "Content-Type: {$document->mimeType}\r\n\r\n{$document->contents}\r\n--{$boundary}--\r\n";

        $json = $this->json($this->call('POST', '/transientDocuments', $body, "multipart/form-data; boundary={$boundary}"), 'POST /transientDocuments');
        $id = $json['transientDocumentId'] ?? null;

        return is_string($id) && $id !== '' ? $id : throw new ProviderError('adobe', "Uploading \"{$name}\" returned no transientDocumentId.");
    }

    /** The account's API access point, e.g. https://api.na1.adobesign.com (no trailing slash). */
    public function accessPoint(): string
    {
        if ($this->accessPoint !== null) {
            return $this->accessPoint;
        }

        $cached = $this->cache?->get($this->accessPointCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $this->accessPoint = $cached;
        }

        $url = $this->discoveryHost . self::API_PATH . '/baseUris';
        $response = $this->send($this->request('GET', $url, $this->token(), null, null, 'application/json'), 'GET /baseUris');
        if ($response->getStatusCode() >= 400) {
            throw $this->error($response, 'GET /baseUris');
        }
        $json = $this->json($response, 'GET /baseUris');
        $point = $json['apiAccessPoint'] ?? null;
        if (! is_string($point) || ! str_starts_with($point, 'https://')) {
            throw new ProviderError('adobe', 'GET /baseUris returned no apiAccessPoint.', $response->getStatusCode());
        }

        $this->accessPoint = self::normalise($point);
        // Adobe's best practices: cache it, and look it up again on INVALID_API_ACCESS_POINT.
        $this->cache?->set($this->accessPointCacheKey(), $this->accessPoint, 30 * 86400);

        return $this->accessPoint;
    }

    private function call(string $method, string $path, ?string $body = null, ?string $contentType = null, string $accept = 'application/json', bool $isRetry = false): ResponseInterface
    {
        $what = "{$method} {$path}";
        $request = $this->request($method, $this->accessPoint() . self::API_PATH . $path, $this->token(), $body, $contentType, $accept);
        $response = $this->send($request, $what);
        $status = $response->getStatusCode();

        if (! $isRetry && $status === 401 && $this->credentials->canRefresh()) {
            $this->forgetToken();

            return $this->call($method, $path, $body, $contentType, $accept, true);
        }
        if (! $isRetry && $status === 403 && $this->configuredAccessPoint === null && $this->code($response) === 'INVALID_API_ACCESS_POINT') {
            // The account moved shard, or a stale cache entry: discover again, once.
            $this->accessPoint = null;
            $this->cache?->delete($this->accessPointCacheKey());

            return $this->call($method, $path, $body, $contentType, $accept, true);
        }
        if ($status >= 400) {
            throw $this->error($response, $what);
        }

        return $response;
    }

    private function token(): string
    {
        if (! $this->credentials->canRefresh()) {
            return (string) $this->credentials->accessToken;
        }

        $now = ($this->clock)();
        if ($this->token !== null && $now < $this->tokenExpiresAt) {
            return $this->token;
        }

        $cached = $this->cache?->get($this->tokenCacheKey());
        if (is_array($cached) && is_string($cached['token'] ?? null) && (int) ($cached['expires_at'] ?? 0) > $now) {
            [$this->token, $this->tokenExpiresAt] = [$cached['token'], (int) $cached['expires_at']];

            return $this->token;
        }

        // A configured access token is used until it is refused (tokenExpiresAt 0 means "never refreshed").
        if ($this->token === null && $this->tokenExpiresAt === 0 && $this->credentials->accessToken !== null) {
            $this->token = $this->credentials->accessToken;
            $this->tokenExpiresAt = PHP_INT_MAX;

            return $this->token;
        }

        return $this->refresh($now);
    }

    /** POST {api_access_point}/oauth/v2/refresh, form-encoded, as Adobe's OAuth guide describes. */
    private function refresh(int $now): string
    {
        $form = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'refresh_token' => $this->credentials->refreshToken,
        ], '', '&', PHP_QUERY_RFC1738);

        // Refresh needs the access point, and discovery needs a token, so refresh mode requires a
        // configured api_access_point (the factory enforces it).
        $request = $this->request('POST', $this->accessPoint() . '/oauth/v2/refresh', null, $form, 'application/x-www-form-urlencoded', 'application/json');
        $response = $this->send($request, 'token refresh');
        $json = json_decode((string) $response->getBody(), true);
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;

        if ($response->getStatusCode() >= 500) {
            throw new ProviderError('adobe', 'Acrobat Sign OAuth service is unavailable. Try again later.', $response->getStatusCode());
        }
        if (! is_string($token) || $token === '') {
            // Only the error code: never echo the response, which could repeat a token back.
            $code = is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : null;
            throw new ProviderError('adobe', 'Refreshing the access token failed. Check client_id, client_secret and refresh_token (refresh tokens expire after 60 days unused).', $response->getStatusCode(), $code);
        }

        // Refresh a minute early. Adobe's example lifetime is 3600 seconds.
        $lifetime = max(60, (int) ($json['expires_in'] ?? 3600) - 60);
        $this->token = $token;
        $this->tokenExpiresAt = $now + $lifetime;
        $this->cache?->set($this->tokenCacheKey(), ['token' => $token, 'expires_at' => $this->tokenExpiresAt], $lifetime);

        return $token;
    }

    private function forgetToken(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 1;       // not 0: the configured access token was refused, don't use it again
        $this->cache?->delete($this->tokenCacheKey());
    }

    private function request(string $method, string $url, ?string $token, ?string $body, ?string $contentType, string $accept): RequestInterface
    {
        $request = $this->requests->createRequest($method, $url)->withHeader('Accept', $accept);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', (string) $contentType)->withBody($this->streams->createStream($body));
        }

        return $request;
    }

    private function send(RequestInterface $request, string $what): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderError('adobe', "{$what}: could not reach Acrobat Sign ({$this->scrub($e->getMessage())})", null, null, $e);
        }
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $response, string $what): array
    {
        $json = json_decode((string) $response->getBody(), true);

        return is_array($json) ? $json : throw new ProviderError('adobe', "{$what} returned a non-JSON response.", $response->getStatusCode());
    }

    private function error(ResponseInterface $response, string $what): ProviderError
    {
        [$code, $message] = $this->parseError($response);
        $message ??= 'Request failed';
        if ($code === 'THROTTLING_TOO_MANY_REQUESTS' && $response->getHeaderLine('Retry-After') !== '') {
            $message .= ' Retry after ' . $response->getHeaderLine('Retry-After') . ' seconds.';
        }

        return new ProviderError('adobe', $this->scrub("{$what}: {$message}"), $response->getStatusCode(), $code);
    }

    private function code(ResponseInterface $response): ?string
    {
        return $this->parseError($response)[0];
    }

    /** @return array{0: ?string, 1: ?string} [code, message] */
    private function parseError(ResponseInterface $response): array
    {
        $json = json_decode((string) $response->getBody(), true);
        if (! is_array($json)) {
            return [null, null];
        }
        $code = is_string($json['code'] ?? null) && $json['code'] !== '' ? $json['code'] : null;
        $message = is_string($json['message'] ?? null) && $json['message'] !== '' ? $json['message'] : null;

        // The OpenAPI examples' shape: {"reason": "INVALID_AGREEMENT_ID: The Agreement ID specified is invalid.", "status": 404}
        $reason = $json['reason'] ?? null;
        if (is_string($reason) && $reason !== '' && ($code === null || $message === null)) {
            if (preg_match('/^([A-Z0-9_]+):\s*(.*)$/s', $reason, $m) === 1) {
                $code ??= $m[1];
                $message ??= $m[2];
            } else {
                $message ??= $reason;
            }
        }

        return [$code, $message];
    }

    private function scrub(string $text): string
    {
        foreach ($this->credentials->secrets() as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return $text;
    }

    private function accessPointCacheKey(): string
    {
        return 'esign_adobe_base_uri_' . sha1($this->discoveryHost . '|' . $this->credentials->clientId . '|' . sha1((string) $this->credentials->accessToken));
    }

    private function tokenCacheKey(): string
    {
        return 'esign_adobe_token_' . sha1((string) $this->configuredAccessPoint . '|' . $this->credentials->clientId . '|' . sha1((string) $this->credentials->refreshToken));
    }

    /** @param array<mixed> $body */
    private static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** "https://api.na1.adobesign.com/" (as Adobe returns it) → "https://api.na1.adobesign.com". */
    private static function normalise(string $accessPoint): string
    {
        return rtrim($accessPoint, '/');
    }
}
