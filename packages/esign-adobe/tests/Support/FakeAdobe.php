<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Esign\Adobe\AdobeApi;
use HamadaEmamTech\Esign\Adobe\AdobeCredentials;
use HamadaEmamTech\Esign\Adobe\AdobeOptions;
use HamadaEmamTech\Esign\Adobe\AdobeProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * An in-memory Acrobat Sign built from the shapes in Adobe's REST v6 OpenAPI description and
 * developer guides (not from live traffic), with the documented traps:
 * - calls on any host but the account's shard answer 403 INVALID_API_ACCESS_POINT;
 * - GET /baseUris on the global host returns apiAccessPoint WITH a trailing slash;
 * - participant set orders must be 1, 2, 3... (400 INVALID_ORDER otherwise);
 * - PUT /formFields only works in AUTHORING (403 AGREEMENT_NOT_IN_AUTHORING_STATE);
 * - a rejected agreement reports status CANCELLED; only its events show REJECTED;
 * - /combinedDocument serves the unsigned PDF before everyone signed;
 * - errors are {"code", "message"}, or the OpenAPI examples' {"reason": "CODE: message", "status"}.
 */
final class FakeAdobe implements ClientInterface
{
    public const TOKEN = 'integration-key-SECRET-1';
    public const CLIENT_ID = 'app-client-id-1';
    public const CLIENT_SECRET = 'app-client-SECRET';
    public const REFRESH_TOKEN = 'refresh-token-SECRET';
    public const DISCOVERY = 'https://api.adobesign.com';
    public const SHARD = 'https://api.na9.adobesign.com/';
    public const OTHER_SHARD = 'https://api.eu9.adobesign.com/';

    /** An A4 page: 595.28 x 841.89 points. */
    public const PDF = "%PDF-1.7\n1 0 obj << /Type /Pages /Kids [2 0 R] /Count 1 >> endobj\n2 0 obj << /Type /Page /Parent 1 0 R /MediaBox [0 0 595.28 841.89] >> endobj\n%%EOF\n";
    public const MIXED_PDF = "%PDF-1.7\n2 0 obj << /Type /Page /MediaBox [0 0 595.28 841.89] >> endobj\n3 0 obj << /Type /Page /MediaBox [0 0 612 792] >> endobj\n%%EOF\n";
    public const SIGNED_PDF = "%PDF-1.7 combined, signed by everyone\n";

    /** @var list<array{method: string, url: string, path: string, headers: array<string, string[]>, body: string, json: mixed}> */
    public array $calls = [];

    /** @var array<string, array{name: string, mime: string, contents: string}> */
    public array $transients = [];

    public int $baseUriCalls = 0;

    public int $refreshCalls = 0;

    /** The shard the account currently lives on. */
    public string $shard = self::SHARD;

    /** Bearer tokens the API accepts right now. */
    /** @var list<string> */
    public array $validTokens = [self::TOKEN];

    public bool $rejectRefresh = false;

    /** Status string to report instead of the real one, to test unknown statuses. */
    public ?string $forcedStatus = null;

    public bool $failFormFields = false;

    /** Answer errors in the OpenAPI examples' {"reason", "status"} shape. */
    public bool $reasonErrors = false;

    /** Put the caller's bearer token into error messages, to prove the driver scrubs it. */
    public bool $echoTokenInErrors = false;

    /** @var array<string, array{id: string, name: string, scope: string, webhookSubscriptionEvents: list<string>, webhookUrlInfo: array{url: string}}> */
    public array $webhooks = [];

    /** @var array<string, array{name: string, state: string, cancelled: bool, cancellation: mixed, expired: bool, rejected: ?string, conversionProblem: bool, sets: list<array{id: string, order: int, email: string, name: string, memberId: string, signed: bool}>, fields: list<mixed>, body: array<mixed>}> */
    private array $agreements = [];

    private int $sequence = 0;

    public function provider(?AdobeOptions $options = null, ?AdobeCredentials $credentials = null, ?string $accessPoint = null, ?CacheInterface $cache = null, ?\Closure $clock = null): AdobeProvider
    {
        $factory = new HttpFactory();

        return new AdobeProvider(
            new AdobeApi($this, $factory, $factory, $credentials ?? new AdobeCredentials(self::TOKEN), self::DISCOVERY, $accessPoint, $cache, $clock),
            $options ?? new AdobeOptions(webhookClientId: self::CLIENT_ID),
        );
    }

    public function refreshingProvider(?string $accessToken = null, ?\Closure $clock = null, ?CacheInterface $cache = null): AdobeProvider
    {
        return $this->provider(null, new AdobeCredentials($accessToken, self::CLIENT_ID, self::CLIENT_SECRET, self::REFRESH_TOKEN), self::SHARD, $cache, $clock);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getScheme() . '://' . $uri->getHost();
        $path = $uri->getPath();
        $body = (string) $request->getBody();
        $json = str_starts_with($request->getHeaderLine('Content-Type'), 'application/json') ? json_decode($body, true) : null;
        $this->calls[] = ['method' => $request->getMethod(), 'url' => (string) $uri, 'path' => $path, 'headers' => $request->getHeaders(), 'body' => $body, 'json' => $json];

        if ($path === '/oauth/v2/refresh') {
            return $this->refresh($body);
        }
        if (! in_array(substr($request->getHeaderLine('Authorization'), 7), $this->validTokens, true) || ! str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')) {
            return $this->error(401, 'INVALID_ACCESS_TOKEN', 'Access token provided is invalid or has expired.', $request);
        }
        if ($path === '/api/rest/v6/baseUris') {
            if ($host !== self::DISCOVERY) {
                return $this->error(404, 'NOT_FOUND', 'Not found', $request);
            }
            ++$this->baseUriCalls;

            return self::ok(['apiAccessPoint' => $this->shard, 'webAccessPoint' => str_replace('//api.', '//secure.', $this->shard)]);
        }
        if ($host . '/' !== $this->shard) {
            return $this->error(403, 'INVALID_API_ACCESS_POINT', 'Request must be made to correct API access point (e.g. use GET /baseUris).', $request);
        }

        $route = substr($path, strlen('/api/rest/v6'));
        $method = $request->getMethod();

        return match (true) {
            $method === 'POST' && $route === '/transientDocuments' => $this->upload($request, $body),
            $method === 'POST' && $route === '/agreements' => $this->create((array) $json),
            $method === 'POST' && $route === '/webhooks' => $this->createWebhook((array) $json),
            $method === 'GET' && $route === '/webhooks' => self::ok(['userWebhookList' => array_values($this->webhooks), 'page' => ['nextCursor' => null]]),
            $method === 'DELETE' && (bool) preg_match('#^/webhooks/([^/]+)$#', $route, $w) => $this->deleteWebhook(rawurldecode($w[1]), $request),
            (bool) preg_match('#^/agreements/([^/]+)(/[a-zA-Z]+)?$#', $route, $m) => $this->agreement($method, rawurldecode($m[1]), $m[2] ?? '', (array) $json, $request),
            default => $this->error(404, 'NOT_FOUND', 'Not found', $request),
        };
    }

    // ---- test controls ----------------------------------------------------------

    public function sign(string $id, string $email): void
    {
        foreach ($this->agreements[$id]['sets'] as &$set) {
            if ($set['email'] === $email) {
                $set['signed'] = true;
            }
        }
    }

    public function signAll(string $id): void
    {
        foreach ($this->agreements[$id]['sets'] as &$set) {
            $set['signed'] = true;
        }
    }

    public function reject(string $id, string $email): void
    {
        $this->agreements[$id]['rejected'] = $email;
    }

    /** Acrobat Sign could not process a document and cancelled the agreement on its own. */
    public function conversionProblem(string $id): void
    {
        $this->agreements[$id]['conversionProblem'] = true;
    }

    public function expire(string $id): void
    {
        $this->agreements[$id]['expired'] = true;
    }

    public function state(string $id): string
    {
        return $this->agreements[$id]['state'];
    }

    /** @return list<string> "METHOD /path" for every API call */
    public function routes(): array
    {
        return array_map(fn (array $c) => $c['method'] . ' ' . $c['path'], $this->calls);
    }

    /** @return mixed the JSON body of the last $method call to $path */
    public function lastJson(string $method, string $path): mixed
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['json'];
            }
        }

        return null;
    }

    // ---- endpoints ----------------------------------------------------------------

    private function refresh(string $body): ResponseInterface
    {
        parse_str($body, $form);
        $valid = ! $this->rejectRefresh
            && ($form['grant_type'] ?? null) === 'refresh_token'
            && ($form['client_id'] ?? null) === self::CLIENT_ID
            && ($form['client_secret'] ?? null) === self::CLIENT_SECRET
            && ($form['refresh_token'] ?? null) === self::REFRESH_TOKEN;
        if (! $valid) {
            // Shape from Adobe's OAuth guide; the description repeats the token to prove it is never echoed.
            return new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'INVALID_TOKEN', 'error_description' => 'Not a valid token: ' . self::REFRESH_TOKEN]));
        }
        $token = 'access-' . ++$this->refreshCalls;
        $this->validTokens[] = $token;

        return self::ok(['access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }

    private function upload(RequestInterface $request, string $body): ResponseInterface
    {
        if (preg_match('/^multipart\/form-data; boundary=(.+)$/', $request->getHeaderLine('Content-Type'), $m) !== 1) {
            return $this->error(415, 'UNSUPPORTED_MEDIA_TYPE', 'Content type was not provided or is not supported.', $request);
        }
        $parts = [];
        foreach (explode('--' . $m[1], $body) as $chunk) {
            if (preg_match('/^\r\nContent-Disposition: form-data; name="([^"]+)"[^\r]*\r\n(?:Content-Type: [^\r]+\r\n)?\r\n(.*)\r\n$/s', $chunk, $p) === 1) {
                $parts[$p[1]] = $p[2];
            }
        }
        if (! isset($parts['File'])) {
            return $this->error(400, 'MISSING_REQUIRED_PARAM', 'Required parameter File is missing.', $request);
        }
        $id = 'transient-' . ++$this->sequence;
        $this->transients[$id] = ['name' => $parts['File-Name'] ?? '', 'mime' => $parts['Mime-Type'] ?? '', 'contents' => $parts['File']];

        return self::ok(['transientDocumentId' => $id]);
    }

    /** @param array<mixed> $body */
    private function create(array $body): ResponseInterface
    {
        foreach ((array) ($body['fileInfos'] ?? []) as $file) {
            if (! isset($this->transients[$file['transientDocumentId'] ?? ''])) {
                return $this->error(404, 'INVALID_TRANSIENTDOCUMENT_ID', 'The transient document ID specified is invalid.');
            }
        }
        $orders = array_map(fn (array $s) => (int) $s['order'], (array) ($body['participantSetsInfo'] ?? []));
        $distinct = array_values(array_unique($orders));
        sort($distinct);
        if ($distinct === [] || $distinct !== range(1, count($distinct))) {
            return $this->error(400, 'INVALID_ORDER', 'The order specified for participant sets is invalid.');
        }
        if (! in_array($body['state'] ?? null, ['IN_PROCESS', 'AUTHORING', 'DRAFT'], true)) {
            return $this->error(400, 'INVALID_AGREEMENT_STATUS', 'Invalid agreement state.');
        }

        $id = 'CBJCHBCAABAA' . ++$this->sequence;
        $sets = [];
        foreach ((array) $body['participantSetsInfo'] as $i => $set) {
            $sets[] = ['id' => "ps-{$this->sequence}-{$i}", 'order' => (int) $set['order'], 'email' => (string) $set['memberInfos'][0]['email'], 'name' => (string) ($set['memberInfos'][0]['name'] ?? ''), 'memberId' => "pm-{$this->sequence}-{$i}", 'signed' => false];
        }
        $this->agreements[$id] = [
            'name' => (string) ($body['name'] ?? ''),
            'state' => (string) $body['state'],
            'cancelled' => false,
            'cancellation' => null,
            'expired' => false,
            'rejected' => null,
            'conversionProblem' => false,
            'sets' => $sets,
            'fields' => [],
            'body' => $body,
        ];

        return self::ok(['id' => $id]);
    }

    /** @param array<mixed> $json */
    private function agreement(string $method, string $id, string $sub, array $json, RequestInterface $request): ResponseInterface
    {
        if (! isset($this->agreements[$id])) {
            return $this->error(404, 'INVALID_AGREEMENT_ID', 'The Agreement ID specified is invalid.', $request);
        }
        $a = &$this->agreements[$id];

        return match ("{$method} {$sub}") {
            'GET ' => self::ok(['id' => $id, 'name' => $a['name'], 'status' => $this->forcedStatus ?? $this->status($id), 'signatureType' => 'ESIGN', 'participantSetsInfo' => array_map(fn (array $s) => ['id' => $s['id'], 'order' => $s['order'], 'role' => 'SIGNER', 'memberInfos' => [['email' => $s['email']]]], $a['sets'])]),
            'GET /members' => self::ok([
                'participantSets' => array_map(fn (array $s) => ['id' => $s['id'], 'order' => $s['order'], 'role' => 'SIGNER', 'status' => $this->setStatus($id, $s), 'memberInfos' => [['id' => $s['memberId'], 'email' => $s['email'], 'name' => $s['name'], 'status' => 'ACTIVE']]], $a['sets']),
                'senderInfo' => ['email' => 'sender@example.test', 'status' => 'COMPLETED'],
                'ccsInfo' => [], 'nextParticipantSets' => [], 'sharesInfo' => [],
            ]),
            'GET /events' => self::ok(['events' => array_values(array_filter([
                ['type' => 'CREATED'],
                ['type' => 'ACTION_REQUESTED'],
                $a['rejected'] !== null ? ['type' => 'REJECTED', 'participantEmail' => $a['rejected'], 'comment' => 'No thanks'] : null,
                $a['conversionProblem'] ? ['type' => 'AUTO_CANCELLED_CONVERSION_PROBLEM'] : null,
                $a['cancelled'] ? ['type' => 'RECALLED'] : null,
            ]))]),
            'GET /combinedDocument' => new Response(200, ['Content-Type' => 'application/pdf'], $this->status($id) === 'SIGNED' ? self::SIGNED_PDF : self::PDF),
            'PUT /formFields' => $this->formFields($id, $json),
            'PUT /state' => $this->transition($id, $json),
            'POST /reminders' => new Response(201, ['Content-Type' => 'application/json'], '{"id":"reminder-1"}'),
            default => $this->error(404, 'NOT_FOUND', 'Not found', $request),
        };
    }

    /** @param array<mixed> $json */
    private function createWebhook(array $json): ResponseInterface
    {
        $id = 'webhook-' . ++$this->sequence;
        $this->webhooks[$id] = [
            'id' => $id,
            'name' => (string) ($json['name'] ?? ''),
            'scope' => (string) ($json['scope'] ?? ''),
            'webhookSubscriptionEvents' => array_values((array) ($json['webhookSubscriptionEvents'] ?? [])),
            'webhookUrlInfo' => ['url' => (string) ($json['webhookUrlInfo']['url'] ?? '')],
        ];

        // The guide says 201 with a Location header; the OpenAPI description says 200. 201 here.
        return new Response(201, ['Content-Type' => 'application/json', 'Location' => "/api/rest/v6/webhooks/{$id}"], (string) json_encode(['id' => $id]));
    }

    private function deleteWebhook(string $id, RequestInterface $request): ResponseInterface
    {
        if (! isset($this->webhooks[$id])) {
            return $this->error(404, 'INVALID_WEBHOOK_ID', 'The webhook ID specified is invalid.', $request);
        }
        unset($this->webhooks[$id]);

        return new Response(204);
    }

    /** @param array<mixed> $json */
    private function formFields(string $id, array $json): ResponseInterface
    {
        if ($this->agreements[$id]['state'] !== 'AUTHORING') {
            return $this->error(403, 'AGREEMENT_NOT_IN_AUTHORING_STATE', 'The agreement is not in authoring state.');
        }
        if ($this->failFormFields) {
            return $this->error(400, 'INVALID_FORM_FIELD_PROPERTY', 'Form field has an invalid property.');
        }
        $setIds = array_column($this->agreements[$id]['sets'], 'id');
        foreach ((array) ($json['fields'] ?? []) as $field) {
            if (! in_array($field['assignee'] ?? null, $setIds, true)) {
                return $this->error(404, 'INVALID_PARTICIPANT_SET_ID', 'The participant set ID specified is invalid.');
            }
        }
        $this->agreements[$id]['fields'] = (array) ($json['fields'] ?? []);

        return self::ok(['fields' => $this->agreements[$id]['fields']]);
    }

    /** @param array<mixed> $json */
    private function transition(string $id, array $json): ResponseInterface
    {
        $a = &$this->agreements[$id];
        $to = $json['state'] ?? null;
        if ($to === 'IN_PROCESS' && $a['state'] === 'AUTHORING') {
            $a['state'] = 'IN_PROCESS';

            return new Response(200);
        }
        if ($to === 'CANCELLED' && in_array($this->status($id), ['OUT_FOR_SIGNATURE', 'AUTHORING'], true)) {
            $a['cancelled'] = true;
            $a['cancellation'] = $json['agreementCancellationInfo'] ?? null;

            return new Response(200);
        }

        return $this->error(403, 'OPERATION_NOT_ALLOWED', 'Requested Operation is not allowed.');
    }

    private function status(string $id): string
    {
        $a = $this->agreements[$id];

        return match (true) {
            $a['cancelled'], $a['rejected'] !== null, $a['conversionProblem'] => 'CANCELLED',
            $a['expired'] => 'EXPIRED',
            $a['state'] === 'AUTHORING' => 'AUTHORING',
            array_filter($a['sets'], fn (array $s) => ! $s['signed']) === [] => 'SIGNED',
            default => 'OUT_FOR_SIGNATURE',
        };
    }

    /** @param array{id: string, order: int, email: string, memberId: string, signed: bool} $set */
    private function setStatus(string $id, array $set): string
    {
        $status = $this->status($id);
        if ($set['signed']) {
            return 'COMPLETED';
        }
        if ($status === 'CANCELLED' || $status === 'EXPIRED') {
            return $status;
        }
        if ($status === 'AUTHORING') {
            return 'WAITING_FOR_AUTHORING';
        }
        $open = array_map(fn (array $s) => $s['order'], array_filter($this->agreements[$id]['sets'], fn (array $s) => ! $s['signed']));

        return $set['order'] === min($open) ? 'WAITING_FOR_MY_SIGNATURE' : 'NOT_YET_VISIBLE';
    }

    private function error(int $status, string $code, string $message, ?RequestInterface $request = null): ResponseInterface
    {
        if ($this->echoTokenInErrors && $request !== null) {
            $message .= ' (' . $request->getHeaderLine('Authorization') . ')';
        }
        $body = $this->reasonErrors ? ['reason' => "{$code}: {$message}", 'status' => $status] : ['code' => $code, 'message' => $message];

        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function ok(mixed $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
