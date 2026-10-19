<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Esign\Dropbox\DropboxApi;
use HamadaEmamTech\Esign\Dropbox\DropboxCredentials;
use HamadaEmamTech\Esign\Dropbox\DropboxOptions;
use HamadaEmamTech\Esign\Dropbox\DropboxProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An in-memory Dropbox Sign built from the shapes in its official API reference and OpenAPI
 * spec (not from a live account):
 * - HTTP Basic auth, API key as the username, empty password; 401 "unauthorized" otherwise;
 * - errors are {"error": {"error_msg", "error_name"}} with a 4xx status;
 * - send returns {"signature_request": {...}} with signatures[].status_code "awaiting_signature";
 * - cancel answers 200 with an empty body; afterwards GET answers 410 "deleted";
 * - cancelling a completed request is 400 "signature_request_cancel_failed";
 * - remind takes a JSON body {email_address}, refuses a signer who isn't on the request, and
 *   refuses a second reminder within the hour;
 * - files answers 409 "conflict" while the PDF is being prepared, then the PDF bytes.
 */
final class FakeDropbox implements ClientInterface
{
    public const API_KEY = 'test-api-key-0123456789abcdef';
    public const SIGNED = "%PDF-1.7 signed by everyone\n";

    /** @var list<array{method: string, path: string, query: string, json: mixed, parts: list<array{name: string, contents: string, filename: ?string, contentType: ?string}>}> */
    public array $calls = [];

    /** The files endpoint answers 409 this many times. */
    public int $filesPreparing = 0;

    /** Report this status_code for every signer, to test unknown statuses. */
    public ?string $forcedStatusCode = null;

    /** @var array<array-key, array{cancelled: bool, has_error: bool, expires_at: ?int, signatures: list<array<string, mixed>>, metadata: array<string, string>}> */
    private array $requests = [];

    private int $sequence = 0;

    public function provider(?DropboxOptions $options = null, string $apiKey = self::API_KEY): DropboxProvider
    {
        $factory = new HttpFactory();
        $credentials = new DropboxCredentials($apiKey);

        return new DropboxProvider(
            new DropboxApi($this, $factory, $factory, $credentials, 'https://api.dropbox-sign.test/v3'),
            $credentials,
            $options ?? new DropboxOptions(),
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $json = str_starts_with($request->getHeaderLine('Content-Type'), 'application/json') ? json_decode((string) $request->getBody(), true) : null;
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $path,
            'query' => $request->getUri()->getQuery(),
            'json' => $json,
            'parts' => self::parseMultipart($request->getHeaderLine('Content-Type'), (string) $request->getBody()),
        ];

        if ($request->getHeaderLine('Authorization') !== 'Basic ' . base64_encode(self::API_KEY . ':')) {
            return self::error(401, 'unauthorized', 'Unauthorized api key');
        }

        return match (true) {
            $path === '/v3/signature_request/send' && $request->getMethod() === 'POST' => $this->send(),
            (bool) preg_match('#^/v3/signature_request/cancel/([^/]+)$#', $path, $m) => $this->cancel($m[1]),
            (bool) preg_match('#^/v3/signature_request/remind/([^/]+)$#', $path, $m) => $this->remind($m[1], $json),
            (bool) preg_match('#^/v3/signature_request/files/([^/]+)$#', $path, $m) => $this->files($m[1]),
            (bool) preg_match('#^/v3/signature_request/([^/]+)$#', $path, $m) => $this->get($m[1]),
            default => self::error(404, 'not_found', 'Not found'),
        };
    }

    // ---- test controls ----------------------------------------------------------

    public function sign(string $id, string $email): void
    {
        $this->setStatus($id, $email, 'signed');
    }

    public function decline(string $id, string $email): void
    {
        $this->setStatus($id, $email, 'declined');
    }

    public function signAll(string $id): void
    {
        foreach ($this->requests[$id]['signatures'] as $signature) {
            $this->sign($id, (string) $signature['signer_email_address']);
        }
    }

    public function breakRequest(string $id): void
    {
        $this->requests[$id]['has_error'] = true;
    }

    public function expire(string $id): void
    {
        $this->requests[$id]['expires_at'] = time() - 60;
    }

    /** @return mixed the JSON body of the last call to $path */
    public function lastJson(string $path): mixed
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $path) {
                return $call['json'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(fn (array $c) => $c['path'], $this->calls);
    }

    /**
     * The form fields of the last call to $path, name => contents.
     *
     * @return array<string, string>
     */
    public function lastForm(string $path): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $path) {
                $form = [];
                foreach ($call['parts'] as $part) {
                    $form[$part['name']] = $part['contents'];
                }

                return $form;
            }
        }

        return [];
    }

    /** @return list<array{name: string, contents: string, filename: ?string, contentType: ?string}> */
    public function lastParts(string $path): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $path) {
                return $call['parts'];
            }
        }

        return [];
    }

    // ---- endpoints ----------------------------------------------------------------

    private function send(): ResponseInterface
    {
        $form = $this->lastForm('/v3/signature_request/send');
        if (! isset($form['files[0]'])) {
            return self::error(400, 'bad_request', 'Must specify files or file_urls', 'files');
        }
        $signatures = [];
        for ($i = 0; isset($form["signers[{$i}][email_address]"]); ++$i) {
            $signatures[] = [
                'signature_id' => "sig-{$i}",
                'signer_email_address' => $form["signers[{$i}][email_address]"],
                'signer_name' => $form["signers[{$i}][name]"] ?? '',
                'signer_role' => null,
                'order' => isset($form["signers[{$i}][order]"]) ? (int) $form["signers[{$i}][order]"] : null,
                'status_code' => 'awaiting_signature',
                'decline_reason' => null,
                'signed_at' => null,
                'has_pin' => false,
                'has_sms_auth' => isset($form["signers[{$i}][sms_phone_number]"]),
                'error' => null,
            ];
        }
        if ($signatures === []) {
            return self::error(400, 'bad_request', 'Must specify at least one signer', 'signers');
        }
        $metadata = [];
        foreach ($form as $name => $value) {
            if (preg_match('/^metadata\[(.+)\]$/', $name, $m)) {
                $metadata[$m[1]] = $value;
            }
        }

        $id = sprintf('%040x', ++$this->sequence);
        $this->requests[$id] = ['cancelled' => false, 'has_error' => false, 'expires_at' => null, 'signatures' => $signatures, 'metadata' => $metadata];

        return self::json(200, ['signature_request' => $this->object($id)]);
    }

    private function get(string $id): ResponseInterface
    {
        if (! isset($this->requests[$id])) {
            return self::error(404, 'not_found', 'Not found');
        }
        if ($this->requests[$id]['cancelled']) {
            return self::error(410, 'deleted', 'This resource has been deleted');
        }

        return self::json(200, ['signature_request' => $this->object($id)]);
    }

    private function cancel(string $id): ResponseInterface
    {
        if (! isset($this->requests[$id]) || $this->requests[$id]['cancelled'] || $this->complete($id)) {
            return self::error(400, 'signature_request_cancel_failed', 'Signature request cannot be cancelled');
        }
        $this->requests[$id]['cancelled'] = true;

        return new Response(200, ['Content-Type' => 'application/json'], '');
    }

    /** @var array<array-key, bool> request IDs reminded in the last hour */
    private array $reminded = [];

    private function remind(string $id, mixed $body): ResponseInterface
    {
        if (! isset($this->requests[$id])) {
            return self::error(404, 'not_found', 'Not found');
        }
        $email = is_array($body) ? ($body['email_address'] ?? null) : null;
        if (! in_array($email, array_column($this->requests[$id]['signatures'], 'signer_email_address'), true)) {
            return self::error(400, 'bad_request', 'No signer with that email address', 'email_address');
        }
        if (isset($this->reminded[$id])) {
            return self::error(400, 'invalid_reminder', 'You cannot send a reminder within 1 hour of the last reminder');
        }
        $this->reminded[$id] = true;

        return self::json(200, ['signature_request' => $this->object($id)]);
    }

    private function files(string $id): ResponseInterface
    {
        if (! isset($this->requests[$id])) {
            return self::error(404, 'not_found', 'Not found');
        }
        if ($this->filesPreparing > 0) {
            --$this->filesPreparing;

            return self::error(409, 'conflict', 'Files are still being processed. Please try again later.');
        }

        return new Response(200, ['Content-Type' => 'application/pdf'], self::SIGNED);
    }

    // ---- helpers ------------------------------------------------------------------

    private function setStatus(string $id, string $email, string $status): void
    {
        foreach ($this->requests[$id]['signatures'] as &$signature) {
            if ($signature['signer_email_address'] === $email) {
                $signature['status_code'] = $status;
            }
        }
    }

    private function complete(string $id): bool
    {
        return array_filter($this->requests[$id]['signatures'], fn (array $s) => $s['status_code'] !== 'signed') === [];
    }

    /** @return array<string, mixed> */
    private function object(string $id): array
    {
        $request = $this->requests[$id];
        $signatures = $request['signatures'];
        if ($this->forcedStatusCode !== null) {
            $signatures = array_map(fn (array $s) => ['status_code' => $this->forcedStatusCode] + $s, $signatures);
        }
        $declined = array_filter($signatures, fn (array $s) => $s['status_code'] === 'declined') !== [];

        return [
            'test_mode' => true,
            'signature_request_id' => $id,
            'title' => 'Please sign',
            'metadata' => $request['metadata'],
            'created_at' => 1_700_000_000,
            'expires_at' => $request['expires_at'],
            'is_complete' => $this->complete($id),
            'is_declined' => $declined,
            'has_error' => $request['has_error'],
            'files_url' => "https://api.dropbox-sign.test/v3/signature_request/files/{$id}",
            'signing_url' => null,
            'details_url' => "https://app.dropbox-sign.test/home/manage?guid={$id}",
            'signatures' => $signatures,
        ];
    }

    /** @return list<array{name: string, contents: string, filename: ?string, contentType: ?string}> */
    private static function parseMultipart(string $contentType, string $body): array
    {
        if (! preg_match('/boundary=([^;]+)/', $contentType, $m)) {
            return [];
        }
        $parts = [];
        foreach (explode('--' . $m[1], $body) as $chunk) {
            if (! str_starts_with($chunk, "\r\n") || ! str_contains($chunk, "\r\n\r\n")) {
                continue;
            }
            [$head, $contents] = explode("\r\n\r\n", substr($chunk, 2), 2);
            preg_match('/name="((?:[^"\\\\]|\\\\.)*)"/', $head, $name);
            preg_match('/filename="((?:[^"\\\\]|\\\\.)*)"/', $head, $filename);
            preg_match('/Content-Type: (.+)/i', $head, $type);
            $parts[] = [
                'name' => stripslashes($name[1] ?? ''),
                'contents' => substr($contents, 0, -2),     // the CRLF before the next boundary
                'filename' => isset($filename[1]) ? stripslashes($filename[1]) : null,
                'contentType' => isset($type[1]) ? trim($type[1]) : null,
            ];
        }

        return $parts;
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function error(int $status, string $name, string $message, ?string $path = null): ResponseInterface
    {
        return self::json($status, ['error' => array_filter(['error_msg' => $message, 'error_name' => $name, 'error_path' => $path])]);
    }
}
