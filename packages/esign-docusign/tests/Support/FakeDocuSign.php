<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Esign\DocuSign\DocuSignApi;
use HamadaEmamTech\Esign\DocuSign\DocuSignCredentials;
use HamadaEmamTech\Esign\DocuSign\DocuSignOptions;
use HamadaEmamTech\Esign\DocuSign\DocuSignProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * An in-memory DocuSign built from the request and response shapes in DocuSign's documentation
 * (not recorded from a live account):
 * - POST https://account-d.docusign.com/oauth/token takes grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer
 *   and an RS256 JWT, whose signature is checked against the public key; it answers
 *   {"access_token", "token_type": "Bearer", "expires_in": 3600}, or 400 {"error": "consent_required" | "invalid_grant"};
 * - GET /oauth/userinfo lists the user's accounts with account_id, is_default and base_uri;
 * - the REST API lives under {base_uri}/restapi/v2.1/accounts/{accountId} and answers errors with
 *   {"errorCode", "message"};
 * - an envelope goes sent → delivered → signed (briefly) → completed, or declined / voided;
 * - documents/combined serves the PDF at any stage: the unsigned one until the envelope is completed;
 * - voiding a completed or voided envelope fails with ENVELOPE_CANNOT_VOID_INVALID_STATE;
 * - PUT /envelopes/{id}/recipients?resend_envelope=true resends; a recipient who already signed comes
 *   back as HTTP 200 with errorDetails RECIPIENT_UPDATE_FAILED in recipientUpdateResults;
 * - GET and PUT /envelopes/{id}/notification hold reminders and expirations (string values).
 */
final class FakeDocuSign implements ClientInterface
{
    public const AUTH_HOST = 'account-d.docusign.com';
    public const INTEGRATION_KEY = '00000000-0000-0000-0000-0000000000aa';
    public const USER_ID = '00000000-0000-0000-0000-0000000000bb';
    public const ACCOUNT_ID = '00000000-0000-0000-0000-0000000000cc';
    public const OTHER_ACCOUNT_ID = '00000000-0000-0000-0000-0000000000dd';
    public const BASE_URI = 'https://demo.docusign.net';
    public const ORIGINAL = "%PDF-1.4 original\n";
    public const SIGNED = "%PDF-1.7 completed by every signer\n";

    /** @var list<array{method: string, url: string, body: mixed}> */
    public array $calls = [];

    public int $tokenCalls = 0;

    public int $userInfoCalls = 0;

    public int $expiresIn = 3600;

    /** Answer the next N REST calls with 401, as an expired token would. */
    public int $unauthorizedNext = 0;

    public bool $consentMissing = false;

    public bool $authOutage = false;

    /** Answer every recipient update with RECIPIENT_UPDATE_FAILED inside an HTTP 200. */
    public bool $failRecipientUpdates = false;

    /** Status string to report instead of the real one, to test unknown statuses. */
    public ?string $forcedStatus = null;

    /** @var array<string, mixed>|null the decoded claims of the last JWT that verified */
    public ?array $lastClaims = null;

    /** @var array<string, string>|null the decoded header of the last JWT that verified */
    public ?array $lastJwtHeader = null;

    public const SENT_AT = '2026-10-18T10:00:00.0000000Z';

    /** @var list<array{envelopeId: string, recipientId: string}> */
    public array $resent = [];

    /** @var array<string, array{status: string, voidedReason: ?string, signers: list<array<string, mixed>>, notification: array<string, mixed>}> */
    private array $envelopes = [];

    private int $sequence = 0;

    private static ?string $privateKey = null;

    private static ?string $publicKey = null;

    /** A throwaway RSA key pair, generated at runtime once per test run. Never committed. */
    public static function privateKey(): string
    {
        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || ! openssl_pkey_export($key, $pem)) {
                throw new \RuntimeException('Could not generate a test RSA key.');
            }
            $details = openssl_pkey_get_details($key);
            self::$privateKey = (string) $pem;
            self::$publicKey = is_array($details) ? (string) $details['key'] : '';
        }

        return self::$privateKey;
    }

    public static function publicKey(): string
    {
        self::privateKey();

        return (string) self::$publicKey;
    }

    public function provider(?DocuSignOptions $options = null, ?\Closure $clock = null, ?CacheInterface $cache = null, ?string $accountId = null, ?string $baseUri = null): DocuSignProvider
    {
        return new DocuSignProvider($this->api($clock, $cache, $accountId, $baseUri), $options ?? new DocuSignOptions(['hmac-key-1']));
    }

    public function api(?\Closure $clock = null, ?CacheInterface $cache = null, ?string $accountId = null, ?string $baseUri = null): DocuSignApi
    {
        $factory = new HttpFactory();
        $credentials = new DocuSignCredentials(self::INTEGRATION_KEY, self::USER_ID, self::privateKey(), $accountId);

        return new DocuSignApi($this, $factory, $factory, $credentials, self::AUTH_HOST, $baseUri, $cache, $clock);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $body = (string) $request->getBody();
        $this->calls[] = ['method' => $request->getMethod(), 'url' => (string) $uri, 'body' => str_starts_with($body, '{') ? json_decode($body, true) : $body];

        if ($uri->getHost() === self::AUTH_HOST && $uri->getPath() === '/oauth/token') {
            return $this->token($request, $body);
        }
        if (! $this->authorized($request)) {
            return self::error(401, 'AUTHORIZATION_INVALID_TOKEN', 'The access token provided is expired, revoked or malformed.');
        }
        if ($uri->getHost() === self::AUTH_HOST && $uri->getPath() === '/oauth/userinfo') {
            return $this->userInfo();
        }

        $prefix = '/restapi/v2.1/accounts/' . self::ACCOUNT_ID;
        if ('https://' . $uri->getHost() !== self::BASE_URI || ! str_starts_with($uri->getPath(), $prefix)) {
            return self::error(400, 'USER_DOES_NOT_BELONG_TO_SPECIFIED_ACCOUNT', 'The specified User is not a member of the specified Account.');
        }
        if ($this->unauthorizedNext > 0) {
            --$this->unauthorizedNext;

            return self::error(401, 'AUTHORIZATION_INVALID_TOKEN', 'The access token provided is expired, revoked or malformed.');
        }

        $path = substr($uri->getPath(), strlen($prefix));
        $json = json_decode($body, true);
        parse_str($uri->getQuery(), $query);

        return match (true) {
            $path === '/envelopes' && $request->getMethod() === 'POST' => $this->create(is_array($json) ? $json : []),
            (bool) preg_match('#^/envelopes/([^/]+)$#', $path, $m) && $request->getMethod() === 'GET' => $this->get($m[1], (is_string($query['include'] ?? null) ? $query['include'] : '')),
            (bool) preg_match('#^/envelopes/([^/]+)$#', $path, $m) && $request->getMethod() === 'PUT' => $this->update($m[1], is_array($json) ? $json : []),
            (bool) preg_match('#^/envelopes/([^/]+)/documents/combined$#', $path, $m) => $this->combined($m[1]),
            (bool) preg_match('#^/envelopes/([^/]+)/recipients$#', $path, $m) && $request->getMethod() === 'GET' => $this->recipients($m[1]),
            (bool) preg_match('#^/envelopes/([^/]+)/recipients$#', $path, $m) && $request->getMethod() === 'PUT' => $this->updateRecipients($m[1], is_array($json) ? $json : [], ($query['resend_envelope'] ?? null) === 'true'),
            (bool) preg_match('#^/envelopes/([^/]+)/notification$#', $path, $m) && $request->getMethod() === 'GET' => $this->notification($m[1]),
            (bool) preg_match('#^/envelopes/([^/]+)/notification$#', $path, $m) && $request->getMethod() === 'PUT' => $this->updateNotification($m[1], is_array($json) ? $json : []),
            default => self::error(404, 'INVALID_REQUEST_PARAMETER', 'Not found.'),
        };
    }

    // ---- test controls ----------------------------------------------------------

    public function sign(string $envelopeId, string $email): void
    {
        foreach ($this->envelopes[$envelopeId]['signers'] as &$signer) {
            if ($signer['email'] === $email) {
                $signer['status'] = 'completed';
            }
        }
        unset($signer);
        $this->envelopes[$envelopeId]['status'] = $this->allSigned($envelopeId) ? 'completed' : 'delivered';
    }

    public function signAll(string $envelopeId): void
    {
        foreach ($this->envelopes[$envelopeId]['signers'] as $signer) {
            $this->sign($envelopeId, (string) $signer['email']);
        }
    }

    /** Everyone signed and DocuSign is still processing: the documented short "signed" state. */
    public function processing(string $envelopeId): void
    {
        foreach ($this->envelopes[$envelopeId]['signers'] as &$signer) {
            $signer['status'] = 'signed';
        }
        unset($signer);
        $this->envelopes[$envelopeId]['status'] = 'signed';
    }

    public function decline(string $envelopeId, string $email): void
    {
        foreach ($this->envelopes[$envelopeId]['signers'] as &$signer) {
            if ($signer['email'] === $email) {
                $signer['status'] = 'declined';
            }
        }
        unset($signer);
        $this->envelopes[$envelopeId]['status'] = 'declined';
    }

    /**
     * DocuSign reports expiry as "voided", and documents only that voidedReason says why. The
     * wording here is a placeholder, not DocuSign's documented text.
     */
    public function expire(string $envelopeId, string $reason = 'Envelope has expired.'): void
    {
        $this->envelopes[$envelopeId]['status'] = 'voided';
        $this->envelopes[$envelopeId]['voidedReason'] = $reason;
    }

    /** @return list<string> request URLs without the query */
    public function paths(): array
    {
        return array_map(fn (array $c) => explode('?', $c['url'], 2)[0], $this->calls);
    }

    /** @return array<string, mixed>|null the JSON body of the last call to a URL ending with $suffix */
    public function lastBody(string $method, string $suffix): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && str_ends_with(explode('?', $call['url'], 2)[0], $suffix)) {
                return is_array($call['body']) ? $call['body'] : null;
            }
        }

        return null;
    }

    public function restCalls(): int
    {
        return count(array_filter($this->calls, fn (array $c) => str_contains($c['url'], '/restapi/')));
    }

    // ---- endpoints ----------------------------------------------------------------

    private function token(RequestInterface $request, string $body): ResponseInterface
    {
        if ($this->authOutage) {
            return new Response(503);
        }
        parse_str($body, $form);
        if ($request->getHeaderLine('Content-Type') !== 'application/x-www-form-urlencoded'
            || ($form['grant_type'] ?? null) !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
            return self::oauthError('unsupported_grant_type');
        }
        $claims = $this->verify(is_string($form['assertion'] ?? null) ? $form['assertion'] : '');
        if ($claims === null) {
            return self::oauthError('invalid_grant', 'no_valid_keys_or_signatures');
        }
        if ($this->consentMissing) {
            return self::oauthError('consent_required');
        }
        ++$this->tokenCalls;

        return self::json(200, ['access_token' => 'token-' . $this->tokenCalls, 'token_type' => 'Bearer', 'expires_in' => $this->expiresIn]);
    }

    /**
     * The JWT checks DocuSign documents: RS256 over header.body with the integration's key, iss = the
     * integration key, sub = the user GUID, aud = the auth host without https://, iat/exp in epoch
     * seconds with exp after iat, and the "signature impersonation" scope.
     *
     * @return array<string, mixed>|null the claims, or null when the JWT is not acceptable
     */
    private function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $decode = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if (openssl_verify($parts[0] . '.' . $parts[1], $decode($parts[2]), self::publicKey(), OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        $header = json_decode($decode($parts[0]), true);
        $claims = json_decode($decode($parts[1]), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ($header['typ'] ?? null) !== 'JWT') {
            return null;
        }
        $scopes = explode(' ', (string) ($claims['scope'] ?? ''));
        $ok = ($claims['iss'] ?? null) === self::INTEGRATION_KEY
            && ($claims['sub'] ?? null) === self::USER_ID
            && ($claims['aud'] ?? null) === self::AUTH_HOST
            && is_int($claims['iat'] ?? null) && is_int($claims['exp'] ?? null) && $claims['exp'] > $claims['iat']
            && in_array('signature', $scopes, true) && in_array('impersonation', $scopes, true);
        if (! $ok) {
            return null;
        }
        $this->lastJwtHeader = $header;
        $this->lastClaims = $claims;

        return $claims;
    }

    private function authorized(RequestInterface $request): bool
    {
        return $this->tokenCalls > 0 && $request->getHeaderLine('Authorization') === 'Bearer token-' . $this->tokenCalls;
    }

    private function userInfo(): ResponseInterface
    {
        ++$this->userInfoCalls;

        return self::json(200, [
            'sub' => self::USER_ID,
            'name' => 'Example User',
            'email' => 'user@example.test',
            'accounts' => [
                ['account_id' => self::OTHER_ACCOUNT_ID, 'is_default' => false, 'account_name' => 'Other', 'base_uri' => 'https://eu.docusign.net'],
                ['account_id' => self::ACCOUNT_ID, 'is_default' => true, 'account_name' => 'Example', 'base_uri' => self::BASE_URI],
            ],
        ]);
    }

    /** @param array<string, mixed> $body an envelopeDefinition */
    private function create(array $body): ResponseInterface
    {
        $documents = is_array($body['documents'] ?? null) ? $body['documents'] : [];
        $signers = is_array($body['recipients']['signers'] ?? null) ? $body['recipients']['signers'] : [];
        if (! is_string($body['emailSubject'] ?? null) || $documents === [] || $signers === []) {
            return self::error(400, 'INVALID_REQUEST_PARAMETER', 'emailSubject, documents and recipients are required to send.');
        }
        $documentIds = [];
        foreach ($documents as $document) {
            if (! is_string($document['documentBase64'] ?? null) || ! is_string($document['documentId'] ?? null) || ! is_string($document['name'] ?? null)) {
                return self::error(400, 'INVALID_REQUEST_PARAMETER', 'Each document needs documentBase64, documentId and name.');
            }
            $documentIds[] = $document['documentId'];
        }
        $stored = [];
        foreach ($signers as $signer) {
            foreach (['email', 'name', 'recipientId', 'routingOrder'] as $key) {
                if (! is_string($signer[$key] ?? null) || $signer[$key] === '') {
                    return self::error(400, 'INVALID_REQUEST_PARAMETER', "Signer {$key} is required.");
                }
            }
            foreach ($signer['tabs']['signHereTabs'] ?? [] as $tab) {
                if (! in_array($tab['documentId'] ?? null, $documentIds, true) || ($tab['recipientId'] ?? null) !== $signer['recipientId']) {
                    return self::error(400, 'INVALID_REQUEST_PARAMETER', 'A tab refers to an unknown document or recipient.');
                }
            }
            $stored[] = ['recipientId' => $signer['recipientId'], 'email' => $signer['email'], 'name' => $signer['name'], 'routingOrder' => $signer['routingOrder'], 'status' => 'sent'];
        }

        $id = sprintf('00000000-0000-0000-0000-%012d', ++$this->sequence);
        $this->envelopes[$id] = [
            'status' => ($body['status'] ?? 'created') === 'sent' ? 'sent' : 'created',
            'voidedReason' => null,
            'signers' => $stored,
            'notification' => [
                'useAccountDefaults' => 'false',
                'reminders' => ['reminderEnabled' => 'true', 'reminderDelay' => '2', 'reminderFrequency' => '3'],
                'expirations' => ['expireEnabled' => 'true', 'expireAfter' => '120', 'expireWarn' => '5'],
            ],
        ];

        return self::json(201, ['envelopeId' => $id, 'status' => $this->envelopes[$id]['status'], 'statusDateTime' => '2026-10-18T10:00:00.0000000Z', 'uri' => "/envelopes/{$id}"]);
    }

    private function get(string $id, string $include): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
        }
        $envelope = $this->envelopes[$id];
        $data = ['envelopeId' => $id, 'status' => $this->forcedStatus ?? $envelope['status'], 'initialSentDateTime' => self::SENT_AT, 'statusChangedDateTime' => self::SENT_AT];
        if ($envelope['voidedReason'] !== null) {
            $data['voidedReason'] = $envelope['voidedReason'];
        }
        if (in_array('recipients', explode(',', $include), true)) {
            $data['recipients'] = ['signers' => $envelope['signers'], 'recipientCount' => (string) count($envelope['signers'])];
        }

        return self::json(200, $data);
    }

    /** @param array<string, mixed> $body */
    private function update(string $id, array $body): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
        }
        if (($body['status'] ?? null) !== 'voided' || ! is_string($body['voidedReason'] ?? null) || $body['voidedReason'] === '') {
            return self::error(400, 'INVALID_REQUEST_PARAMETER', 'Only voiding with a voidedReason is supported by this fake.');
        }
        if (in_array($this->envelopes[$id]['status'], ['completed', 'voided', 'declined'], true)) {
            return self::error(400, 'ENVELOPE_CANNOT_VOID_INVALID_STATE', 'Only envelopes in the \'Sent\' or \'Delivered\' states may be voided.');
        }
        $this->envelopes[$id]['status'] = 'voided';
        $this->envelopes[$id]['voidedReason'] = $body['voidedReason'];

        return self::json(200, ['envelopeId' => $id]);
    }

    private function recipients(string $id): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
        }

        return self::json(200, ['signers' => $this->envelopes[$id]['signers'], 'recipientCount' => (string) count($this->envelopes[$id]['signers'])]);
    }

    /** @param array<string, mixed> $body */
    private function updateRecipients(string $id, array $body, bool $resend): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
        }
        if (! in_array($this->envelopes[$id]['status'], ['created', 'sent', 'delivered', 'correct'], true)) {
            return self::error(400, 'ENVELOPE_INVALID_STATUS', 'Invalid envelope status. Envelope status is not one of: Created, Sent, Delivered, Correct.');
        }
        $results = [];
        foreach (is_array($body['signers'] ?? null) ? $body['signers'] : [] as $update) {
            if (isset($update['email'])) {
                return self::error(400, 'INVALID_REQUEST_PARAMETER', 'This fake does not model address corrections.');
            }
            $recipientId = (string) ($update['recipientId'] ?? '');
            $signer = array_values(array_filter($this->envelopes[$id]['signers'], fn (array $s) => $s['recipientId'] === $recipientId))[0] ?? null;
            if ($signer === null || $signer['status'] === 'completed' || $this->failRecipientUpdates) {
                $results[] = ['recipientId' => $recipientId, 'errorDetails' => ['errorCode' => 'RECIPIENT_UPDATE_FAILED', 'message' => 'The recipient could not be updated. Recipient not in state that allows correction.']];
                continue;
            }
            if ($resend) {
                $this->resent[] = ['envelopeId' => $id, 'recipientId' => $recipientId];
            }
            $results[] = ['recipientId' => $recipientId];
        }

        return self::json(200, ['recipientUpdateResults' => $results]);
    }

    private function notification(string $id): ResponseInterface
    {
        return isset($this->envelopes[$id])
            ? self::json(200, $this->envelopes[$id]['notification'])
            : self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
    }

    /** @param array<string, mixed> $body an envelopeNotificationRequest */
    private function updateNotification(string $id, array $body): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
        }
        // Whatever is left out is not kept: the request's settings replace the envelope's.
        $this->envelopes[$id]['notification'] = $body;

        return self::json(200, $body);
    }

    /** @return array<string, mixed> */
    public function notificationOf(string $id): array
    {
        return $this->envelopes[$id]['notification'];
    }

    private function combined(string $id): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::error(400, 'ENVELOPE_DOES_NOT_EXIST', 'The envelope specified either does not exist or you have no rights to it.');
        }
        $pdf = $this->envelopes[$id]['status'] === 'completed' ? self::SIGNED : self::ORIGINAL;

        return new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'file; filename="combined.pdf"'], $pdf);
    }

    private function allSigned(string $id): bool
    {
        return array_filter($this->envelopes[$id]['signers'], fn (array $s) => $s['status'] !== 'completed') === [];
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private static function error(int $status, string $code, string $message): ResponseInterface
    {
        return self::json($status, ['errorCode' => $code, 'message' => $message]);
    }

    private static function oauthError(string $error, ?string $description = null): ResponseInterface
    {
        return self::json(400, array_filter(['error' => $error, 'error_description' => $description]));
    }
}
