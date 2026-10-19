<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Esign\BoldSign\BoldSignApi;
use HamadaEmamTech\Esign\BoldSign\BoldSignCredentials;
use HamadaEmamTech\Esign\BoldSign\BoldSignOptions;
use HamadaEmamTech\Esign\BoldSign\BoldSignProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An in-memory BoldSign built from the shapes in BoldSign's API documentation (not from a live
 * account):
 * - POST /v1/document/send answers 201 {"documentId": "..."};
 * - GET /v1/document/properties answers the document with "status" and "signerDetails"
 *   (each signer "NotCompleted" or "Completed", with "order");
 * - POST /v1/document/revoke needs a Message, answers 204, and refuses completed documents;
 * - GET /v1/document/download answers the PDF bytes;
 * - GET /v1/document/getEmbeddedSignLink answers {"signLink": "..."};
 * - POST /v1/document/remind?receiverEmails= answers 204;
 * - PATCH /v1/document/extendExpiry takes {"NewExpiryValue": "yyyy-MM-dd"}, answers 200, and refuses
 *   documents that are no longer open ("only ... on documents that have not been signed yet");
 * - a wrong X-API-KEY is a 401.
 *
 * BoldSign documents its error status codes but not the error body, so the {"error": "..."} bodies
 * here are an assumption; the driver copes with any body, or none.
 */
final class FakeBoldSign implements ClientInterface
{
    public const API_KEY = 'test-api-key-0123456789';
    public const SECRET = 'whsec-test-secret';
    public const ORIGINAL = "%PDF-1.7 original\n";
    public const SIGNED = "%PDF-1.7 signed and sealed by BoldSign\n";

    /** @var list<array{method: string, path: string, query: array<string, string>, body: mixed, headers: array<string, list<string>>}> */
    public array $calls = [];

    /** The host of the last request, to check which data center was called. */
    public string $lastHost = '';

    /** Answer the next call with this HTTP status and body instead. */
    public ?int $failWith = null;

    public string $failBody = '';

    /** Status string to report instead of the real one. */
    public ?string $forcedStatus = null;

    /** @var array<string, string> documentId => the last NewExpiryValue accepted */
    public array $expiries = [];

    /** @var array<string, array{status: string, signers: list<array<string, mixed>>}> */
    private array $documents = [];

    private int $sequence = 0;

    public function provider(?BoldSignOptions $options = null, ?\Closure $clock = null, string $apiKey = self::API_KEY): BoldSignProvider
    {
        $factory = new HttpFactory();

        return new BoldSignProvider(
            new BoldSignApi($this, $factory, $factory, new BoldSignCredentials($apiKey), 'https://api.boldsign.test'),
            $options ?? new BoldSignOptions(webhookSecret: self::SECRET),
            $clock,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $this->lastHost = $request->getUri()->getHost();
        parse_str($request->getUri()->getQuery(), $query);
        /** @var array<string, string> $query */
        $body = (string) $request->getBody();
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $path,
            'query' => $query,
            'body' => $body === '' ? null : json_decode($body, true),
            'headers' => $request->getHeaders(),
        ];

        if ($this->failWith !== null) {
            $status = $this->failWith;
            $this->failWith = null;

            return new Response($status, ['Content-Type' => 'application/json'], $this->failBody);
        }
        if ($request->getHeaderLine('X-API-KEY') !== self::API_KEY) {
            return new Response(401, [], '');
        }

        $id = (string) ($query['documentId'] ?? '');

        return match (true) {
            $path === '/v1/document/send' && $request->getMethod() === 'POST' => $this->send((array) json_decode($body, true)),
            $path === '/v1/document/properties' => $this->properties($id),
            $path === '/v1/document/revoke' && $request->getMethod() === 'POST' => $this->revoke($id, (array) json_decode($body, true)),
            $path === '/v1/document/download' => isset($this->documents[$id])
                ? new Response(200, ['Content-Type' => 'application/pdf'], $this->documents[$id]['status'] === 'Completed' ? self::SIGNED : self::ORIGINAL)
                : self::notFound(),
            $path === '/v1/document/remind' && $request->getMethod() === 'POST' => $this->remind($id, (string) ($query['receiverEmails'] ?? '')),
            $path === '/v1/document/extendExpiry' && $request->getMethod() === 'PATCH' => $this->extendExpiry($id, (array) json_decode($body, true)),
            $path === '/v1/document/getEmbeddedSignLink' => isset($this->documents[$id])
                ? self::json(200, ['signLink' => "https://app.boldsign.test/document/sign/?documentId={$id}"])
                : self::notFound(),
            default => self::notFound(),
        };
    }

    // ---- test controls ----------------------------------------------------------

    public function sign(string $documentId, string $email): void
    {
        foreach ($this->documents[$documentId]['signers'] as &$signer) {
            if ($signer['signerEmail'] === $email) {
                $signer['status'] = 'Completed';
            }
        }
        unset($signer);
        $all = array_filter($this->documents[$documentId]['signers'], fn (array $s) => $s['status'] !== 'Completed') === [];
        if ($all) {
            $this->documents[$documentId]['status'] = 'Completed';
        }
    }

    public function signAll(string $documentId): void
    {
        foreach ($this->documents[$documentId]['signers'] as $signer) {
            $this->sign($documentId, (string) $signer['signerEmail']);
        }
    }

    public function setStatus(string $documentId, string $status): void
    {
        $this->documents[$documentId]['status'] = $status;
    }

    /** @return mixed the JSON body of the last call to $path */
    public function lastBody(string $path): mixed
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $path) {
                return $call['body'];
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
     * A webhook body and headers signed the way BoldSign documents it.
     *
     * @return array{array<string, string>, string}
     */
    public static function webhook(string $documentId, string $eventType, int $timestamp, string $secret = self::SECRET): array
    {
        // Shape from https://developers.boldsign.com/webhooks/sample-event-data/
        $body = (string) json_encode([
            'event' => ['id' => 'evt-' . $eventType, 'created' => $timestamp, 'eventType' => $eventType, 'environment' => 'Test'],
            'data' => ['object' => 'document', 'documentId' => $documentId, 'status' => 'InProgress'],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return [['x-boldsign-signature' => "t={$timestamp}, s0={$signature}", 'x-boldsign-event' => $eventType], $body];
    }

    // ---- endpoints ----------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function send(array $body): ResponseInterface
    {
        if (! is_string($body['Title'] ?? null) || $body['Title'] === '' || ! is_array($body['Signers'] ?? null) || $body['Signers'] === []) {
            return self::json(400, ['error' => 'Title and at least one signer are required.']);
        }
        $id = sprintf('8f59295d-0000-0000-0000-%012d', ++$this->sequence);
        $signers = [];
        foreach ($body['Signers'] as $i => $signer) {
            $signers[] = [
                'id' => "signer-{$i}",
                'signerName' => $signer['Name'] ?? '',
                'signerEmail' => $signer['EmailAddress'] ?? '',
                'status' => 'NotCompleted',
                'order' => $signer['SignerOrder'] ?? 1,
                'signerType' => $signer['SignerType'] ?? 'Signer',
                'authenticationType' => $signer['AuthenticationType'] ?? 'None',
            ];
        }
        $this->documents[$id] = ['status' => 'InProgress', 'signers' => $signers];

        return self::json(201, ['documentId' => $id]);
    }

    private function properties(string $id): ResponseInterface
    {
        if (! isset($this->documents[$id])) {
            return self::notFound();
        }

        return self::json(200, [
            'documentId' => $id,
            'messageTitle' => 'Agreement',
            'status' => $this->forcedStatus ?? $this->documents[$id]['status'],
            'signerDetails' => $this->documents[$id]['signers'],
            'enableSigningOrder' => false,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function revoke(string $id, array $body): ResponseInterface
    {
        if (! isset($this->documents[$id])) {
            return self::notFound();
        }
        if (! is_string($body['Message'] ?? null) || $body['Message'] === '') {
            return self::json(400, ['error' => 'Message is required.']);
        }
        if ($this->documents[$id]['status'] !== 'InProgress') {
            // "Revoke action can only be performed on documents that have not completed the signing process."
            return self::json(400, ['error' => 'The document cannot be revoked.']);
        }
        $this->documents[$id]['status'] = 'Revoked';

        return new Response(204);
    }

    private function remind(string $id, string $email): ResponseInterface
    {
        if (! isset($this->documents[$id])) {
            return self::notFound();
        }
        // Assumed, not documented: a reminder to someone who is not a signer is refused.
        if (! in_array($email, array_column($this->documents[$id]['signers'], 'signerEmail'), true)) {
            return self::json(400, ['error' => 'The receiver is not a signer of this document.']);
        }

        return new Response(204);
    }

    /** @param array<string, mixed> $body */
    private function extendExpiry(string $id, array $body): ResponseInterface
    {
        if (! isset($this->documents[$id])) {
            return self::notFound();
        }
        $value = $body['NewExpiryValue'] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return self::json(400, ['error' => 'NewExpiryValue must be yyyy-MM-dd.']);
        }
        if ($this->documents[$id]['status'] !== 'InProgress') {
            return self::json(400, ['error' => 'The expiry date cannot be extended for this document.']);
        }
        $this->expiries[$id] = $value;

        return new Response(200);
    }

    private static function notFound(): ResponseInterface
    {
        return self::json(404, ['error' => 'Document not found.']);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
