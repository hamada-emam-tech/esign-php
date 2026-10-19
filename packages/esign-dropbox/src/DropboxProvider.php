<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox;

use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\CustomizesWebhookResponse;
use HamadaEmamTech\Esign\Contracts\Features\ReportsSigners;
use HamadaEmamTech\Esign\Contracts\Features\SendsReminders;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\Features\RecognizesWebhooks;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Support\Headers;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeDetails;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerState;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use HamadaEmamTech\Esign\Webhook\WebhookResponse;

/**
 * Dropbox Sign (formerly HelloSign), API v3.
 *
 * Built from Dropbox Sign's official API reference and OpenAPI spec; not yet verified against a
 * live account. What the docs say, and this driver relies on:
 * - POST /signature_request/send takes multipart/form-data: files[n], signers[n][...], title,
 *   subject, message, metadata[...], test_mode, client_id, form_fields_per_document.
 * - GET /signature_request/{id} returns is_complete, is_declined, has_error and one signatures[]
 *   entry per signer with a status_code (documented examples: awaiting_signature, signed, declined).
 * - A cancelled request answers with HTTP 410 and error_name "deleted"; an expired one with
 *   error_name "signature_request_expired".
 * - POST /signature_request/cancel/{id} only queues the cancel: confirmation is the
 *   signature_request_canceled event.
 * - GET /signature_request/files/{id}?file_type=pdf returns the PDF itself, and 409 while it is
 *   still being prepared.
 * - Events arrive as multipart/form-data with the event JSON in a field named "json", signed with
 *   event_hash = HMAC-SHA256(event_time . event_type, api key). Your endpoint must answer HTTP 200
 *   with a body containing WEBHOOK_RESPONSE, or Dropbox Sign retries and eventually clears the URL.
 *   webhookResponse() returns exactly that, so Esign::handleWebhook() answers correctly.
 * - POST /signature_request/remind/{id} (JSON: email_address) re-sends the email, at most once an hour.
 *
 * Not implemented: ExtendsExpiry. /signature_request/update accepts expires_at, but only per
 * signature_id together with a name or email change, and not on requests with an appended
 * signature page; the docs don't say whether it moves the whole request. Not guessed.
 */
final class DropboxProvider implements SignatureProvider, HasCapabilities, ReportsSigners, SendsReminders, CustomizesWebhookResponse, RecognizesWebhooks
{
    /** The body Dropbox Sign requires (with HTTP 200) for every event. Esign::handleWebhook() sends it via webhookResponse(). */
    public const WEBHOOK_RESPONSE = 'Hello API Event Received';

    /** Dropbox Sign's metadata limits, checked before sending. */
    private const METADATA_MAX_KEYS = 10;
    private const METADATA_MAX_KEY_LENGTH = 40;
    private const METADATA_MAX_VALUE_LENGTH = 1000;

    /**
     * Width and height are always measured at 80 DPI, even in the 72 DPI (PDF points) coordinate
     * system that x/y use once "page" is set. Dropbox Sign's FAQ: multiply by 1.111, add 2 to the width.
     */
    private const SIZE_SCALE = 80 / 72;
    private const WIDTH_PADDING = 2;

    public function __construct(
        private readonly DropboxApi $api,
        private readonly DropboxCredentials $credentials,
        private readonly DropboxOptions $options = new DropboxOptions(),
    ) {
    }

    public function name(): string
    {
        return 'dropbox';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            identityMethods: [IdentityMethod::Email, IdentityMethod::SmsOtp],     // sms_otp: not in test mode
            features: [
                Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement,
                Feature::Reminders, Feature::SignerDetails,
                // No SigningUrls: non-embedded requests are signed from Dropbox Sign's email.
                // No WebhookManagement: callback URLs are set on the account or API app.
            ],
            maxDocuments: null,
            asyncCancel: true,        // cancel is queued; signature_request_canceled confirms it
        );
    }

    /**
     * The request's status plus each signer's, from signatures[]. Order is Dropbox Sign's own,
     * 0-based. A cancelled or expired request answers with an error and no signers, so
     * those come back with an empty list.
     */
    public function details(string $envelopeId): EnvelopeDetails
    {
        $ref = new EnvelopeRef('dropbox', $envelopeId);
        $request = $this->fetch($envelopeId);
        if ($request instanceof EnvelopeStatus) {
            return new EnvelopeDetails($ref, $request, []);
        }

        $signers = [];
        foreach ($this->signatures($request) as $signature) {
            $signers[] = new SignerState(
                (string) ($signature['signer_email_address'] ?? ''),
                trim((string) ($signature['signer_name'] ?? '')),
                match (strtolower((string) ($signature['status_code'] ?? ''))) {
                    'signed' => SignerStatus::Signed,
                    'declined' => SignerStatus::Declined,
                    default => SignerStatus::Pending,
                },
                null,
                is_int($signature['order'] ?? null) ? $signature['order'] : null,
            );
        }

        return new EnvelopeDetails($ref, $this->map($request), $signers);
    }

    /**
     * Dropbox Sign refuses a reminder within an hour of the previous one (manual or automatic),
     * and for embedded requests; that comes back as a ProviderError.
     */
    public function remind(string $envelopeId, string $signerEmail): void
    {
        $this->api->postJson('/signature_request/remind/' . rawurlencode($envelopeId), ['email_address' => $signerEmail]);
    }

    /** Dropbox Sign needs HTTP 200 with "Hello API Event Received", for every event. */
    public function webhookResponse(array $headers): WebhookResponse
    {
        return new WebhookResponse(200, self::WEBHOOK_RESPONSE, ['Content-Type' => 'text/plain']);
    }

    /**
     * Envelope::$callbackUrl is not sent: Dropbox Sign has no per-request callback. Events go to the
     * account callback URL, or to the API app's when client_id is set. Configure one of those.
     */
    public function send(Envelope $envelope): SentEnvelope
    {
        $parts = [];
        foreach ($envelope->documents as $i => $document) {
            $parts[] = ['name' => "files[{$i}]", 'contents' => $document->contents, 'filename' => $document->name, 'contentType' => $document->mimeType];
        }
        foreach ($this->signerParts($envelope->signers) as $part) {
            $parts[] = $part;
        }
        $fields = $this->formFields($envelope);
        if ($fields !== []) {
            // The official SDKs send arrays of objects as one JSON-encoded form field.
            $parts[] = ['name' => 'form_fields_per_document', 'contents' => (string) json_encode($fields, JSON_THROW_ON_ERROR)];
        }
        foreach ($this->metadata($envelope->metadata) as $key => $value) {
            $parts[] = ['name' => "metadata[{$key}]", 'contents' => $value];
        }
        $parts[] = ['name' => 'title', 'contents' => $envelope->subject];
        $parts[] = ['name' => 'subject', 'contents' => $envelope->subject];
        if ($envelope->message !== '') {
            $parts[] = ['name' => 'message', 'contents' => $envelope->message];
        }
        if ($this->options->clientId !== null) {
            $parts[] = ['name' => 'client_id', 'contents' => $this->options->clientId];
        }
        $parts[] = ['name' => 'test_mode', 'contents' => $this->options->testMode ? '1' : '0'];

        $response = $this->api->post('/signature_request/send', $parts);
        $id = is_array($response['signature_request'] ?? null) ? ($response['signature_request']['signature_request_id'] ?? null) : null;
        if (! is_string($id) || $id === '') {
            throw new ProviderError('dropbox', 'Signature request was sent without a signature_request_id.');
        }

        // Non-embedded requests are signed from the email Dropbox Sign sends; there are no per-signer links.
        return new SentEnvelope('dropbox', $id, EnvelopeStatus::Sent);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        $request = $this->fetch($envelopeId);

        return $request instanceof EnvelopeStatus ? $request : $this->map($request);
    }

    public function download(string $envelopeId): string
    {
        $status = $this->status($envelopeId);
        if ($status !== EnvelopeStatus::Signed) {
            throw new EsignException("Dropbox Sign request {$envelopeId} is {$status->value}, not signed. There is no signed PDF yet.");
        }

        try {
            $pdf = $this->api->download('/signature_request/files/' . rawurlencode($envelopeId) . '?file_type=pdf');
        } catch (ProviderError $e) {
            if ($e->httpStatus === 409) {
                throw new ProviderError('dropbox', "The signed PDF for {$envelopeId} is still being prepared. Try again shortly.", 409, $e->providerCode, $e);
            }
            throw $e;
        }
        if (! str_starts_with($pdf, '%PDF')) {
            throw new ProviderError('dropbox', "Dropbox Sign returned something other than a PDF for {$envelopeId}.");
        }

        return $pdf;
    }

    /**
     * Dropbox Sign only queues the cancel. status() reports Cancelled once it has gone through,
     * and the signature_request_canceled event confirms it.
     */
    public function cancel(string $envelopeId): void
    {
        $this->api->post('/signature_request/cancel/' . rawurlencode($envelopeId));
    }

    /** Events carry event.event_hash inside the "json" field (multipart, form or plain JSON). */
    public function recognizesWebhook(array $headers, string $rawBody): bool
    {
        try {
            $payload = json_decode($this->eventJson($headers, $rawBody), true);
        } catch (\Throwable) {
            return false;
        }

        return is_array($payload) && is_array($payload['event'] ?? null) && isset($payload['event']['event_hash']);
    }

    /**
     * Accepts the raw multipart/form-data body (pass the Content-Type header so its boundary is
     * known), a url-encoded "json=..." body, or the bare event JSON.
     *
     * Whatever this returns or throws, answer the call with HTTP 200 and WEBHOOK_RESPONSE.
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        if ($this->credentials->apiKey === '') {
            throw new InvalidWebhook('Dropbox Sign events are verified with the API key, and none is configured. Refusing the event.');
        }

        $payload = json_decode($this->eventJson($headers, $rawBody), true);
        if (! is_array($payload) || ! is_array($payload['event'] ?? null)) {
            throw new InvalidWebhook('Dropbox Sign event body has no "event" object.');
        }
        $event = $payload['event'];
        $time = $event['event_time'] ?? null;
        $type = $event['event_type'] ?? null;
        $hash = $event['event_hash'] ?? null;
        $time = is_int($time) ? (string) $time : $time;     // a string in the spec; tolerate a number
        if (! is_string($time) || ! is_string($type) || ! is_string($hash) || $hash === '') {
            throw new InvalidWebhook('Dropbox Sign event is missing event_time, event_type or event_hash.');
        }

        $expected = hash_hmac('sha256', $time . $type, $this->credentials->apiKey);
        if (! hash_equals($expected, strtolower($hash))) {
            throw new InvalidWebhook('Dropbox Sign event_hash does not match.');
        }

        // callback_test and account/template events have no signature request.
        $request = is_array($payload['signature_request'] ?? null) ? $payload['signature_request'] : [];
        $id = $request['signature_request_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidWebhook("Dropbox Sign event \"{$type}\" is not about a signature request.");
        }

        return new WebhookEvent('dropbox', $id, null, $payload);
    }

    // ---- internals ------------------------------------------------------------------

    /**
     * signers[n][name], signers[n][email_address], signers[n][order], and SMS authentication.
     * The field placement refers to signers by n, so they keep the envelope's order here.
     *
     * @param list<Signer> $signers
     *
     * @return list<array{name: string, contents: string}>
     */
    private function signerParts(array $signers): array
    {
        $orders = $this->signingOrder($signers);
        $parts = [];
        foreach ($signers as $i => $signer) {
            $parts[] = ['name' => "signers[{$i}][name]", 'contents' => $signer->name];
            $parts[] = ['name' => "signers[{$i}][email_address]", 'contents' => $signer->email];
            if ($orders !== null) {
                $parts[] = ['name' => "signers[{$i}][order]", 'contents' => (string) $orders[$i]];
            }

            switch ($signer->identity) {
                case IdentityMethod::Email:
                    break;
                case IdentityMethod::SmsOtp:
                    if ($this->options->testMode) {
                        // Documented: sms_phone_number is "not available in test mode".
                        throw new EsignException("Signer \"{$signer->name}\" uses sms_otp, which Dropbox Sign does not offer in test mode. Set test_mode to false.");
                    }
                    if (! preg_match('/^\+[1-9]\d{6,14}$/', (string) $signer->mobile)) {
                        throw new EsignException("Signer \"{$signer->name}\" needs a mobile number in E.164 format (+9665...) for sms_otp.");
                    }
                    $parts[] = ['name' => "signers[{$i}][sms_phone_number]", 'contents' => (string) $signer->mobile];
                    $parts[] = ['name' => "signers[{$i}][sms_phone_number_type]", 'contents' => 'authentication'];
                    break;
                default:
                    throw new EsignException("Dropbox Sign does not support the \"{$signer->identity->value}\" identity method. Use email or sms_otp.");
            }
        }

        return $parts;
    }

    /**
     * Dropbox Sign's order is a strict sequence (0-based in its responses). Signers that all share
     * one order sign in parallel, so no order is sent. Distinct orders become 0, 1, 2... A mix of
     * shared and distinct orders can't be expressed and is refused rather than guessed.
     *
     * @param list<Signer> $signers
     *
     * @return list<int>|null
     */
    private function signingOrder(array $signers): ?array
    {
        $orders = array_map(fn (Signer $s) => $s->order, $signers);
        $distinct = array_values(array_unique($orders));
        if (count($distinct) === 1) {
            return null;
        }
        if (count($distinct) !== count($orders)) {
            throw new EsignException('Dropbox Sign signs either in parallel (every signer has the same order) or in a strict sequence (every order is different). Mixed orders are not supported.');
        }
        sort($distinct);
        $rank = array_flip($distinct);

        return array_map(fn (int $order) => $rank[$order], $orders);
    }

    /**
     * Our fields are PDF points from the top-left of a page, which is Dropbox Sign's 72 DPI
     * coordinate system, used once "page" is set (PDF only). Width and height are scaled to the
     * 80 DPI system Dropbox Sign always measures sizes in.
     *
     * With no fields at all, Dropbox Sign appends a signature page for every signer. If some
     * signers have fields and others don't, the others would have nothing to sign, so that is refused.
     *
     * @return list<array<string, mixed>>
     */
    private function formFields(Envelope $envelope): array
    {
        $withFields = array_filter($envelope->signers, fn (Signer $s) => $s->fields !== []);
        if ($withFields === []) {
            return [];
        }
        if (count($withFields) !== count($envelope->signers)) {
            throw new EsignException('Give every signer a signature field, or none (Dropbox Sign then adds a signature page for everyone).');
        }

        $fields = [];
        foreach ($envelope->signers as $i => $signer) {
            foreach ($signer->fields as $j => $field) {
                $document = $envelope->documents[$field->documentIndex] ?? null;
                if (! $document instanceof Document) {
                    throw new EsignException("Signer \"{$signer->name}\" has a field on document {$field->documentIndex}, but the envelope has " . count($envelope->documents) . ' document(s).');
                }
                if ($document->mimeType !== 'application/pdf') {
                    throw new EsignException("Dropbox Sign places fields by page only on PDFs; \"{$document->name}\" is {$document->mimeType}.");
                }
                $fields[] = $this->field($field, $i, $j);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function field(SignatureField $field, int $signer, int $index): array
    {
        if ($field->page < 1) {
            throw new EsignException('Signature field pages start at 1.');
        }

        return [
            'document_index' => $field->documentIndex,
            'api_id' => "esign_signature_{$signer}_{$index}",
            'type' => 'signature',
            'required' => true,
            'signer' => $signer,
            'page' => $field->page,
            'x' => (int) round($field->x),
            'y' => (int) round($field->y),
            'width' => (int) round($field->width * self::SIZE_SCALE) + self::WIDTH_PADDING,
            'height' => (int) round($field->height * self::SIZE_SCALE),
        ];
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, string>
     */
    private function metadata(array $metadata): array
    {
        if (count($metadata) > self::METADATA_MAX_KEYS) {
            throw new EsignException('Dropbox Sign accepts at most ' . self::METADATA_MAX_KEYS . ' metadata keys.');
        }
        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            if ($key === '' || strlen($key) > self::METADATA_MAX_KEY_LENGTH || preg_match('/[\[\]\r\n"]/', $key)) {
                throw new EsignException("Metadata key \"{$key}\" must be 1 to " . self::METADATA_MAX_KEY_LENGTH . ' characters, without brackets, quotes or line breaks.');
            }
            if (strlen($value) > self::METADATA_MAX_VALUE_LENGTH) {
                throw new EsignException("Metadata \"{$key}\" is longer than Dropbox Sign's " . self::METADATA_MAX_VALUE_LENGTH . ' characters.');
            }
        }

        return $metadata;
    }

    /**
     * The signature_request object, or the final status when Dropbox Sign answers with an error
     * that means one. Documented in the OpenAPI error catalog: "deleted" (410) = cancelled or
     * deleted, "signature_request_expired" = expired.
     *
     * @return array<string, mixed>|EnvelopeStatus
     */
    private function fetch(string $envelopeId): array|EnvelopeStatus
    {
        try {
            $response = $this->api->get('/signature_request/' . rawurlencode($envelopeId));
        } catch (ProviderError $e) {
            if ($e->providerCode === 'deleted' || $e->httpStatus === 410) {
                return EnvelopeStatus::Cancelled;
            }
            if ($e->providerCode === 'signature_request_expired') {
                return EnvelopeStatus::Expired;
            }
            throw $e;
        }
        $request = $response['signature_request'] ?? null;
        if (! is_array($request)) {
            throw new ProviderError('dropbox', "Signature request {$envelopeId} came back without a signature_request object.");
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return list<array<string, mixed>>
     */
    private function signatures(array $request): array
    {
        $signatures = is_array($request['signatures'] ?? null) ? $request['signatures'] : [];

        return array_values(array_filter($signatures, 'is_array'));
    }

    /**
     * The status table in the README. Request-level flags first, then the signers.
     *
     * @param array<string, mixed> $request
     */
    private function map(array $request): EnvelopeStatus
    {
        if (($request['has_error'] ?? false) === true) {
            // "An error occurred" creating the request or during signing: Dropbox Sign gave up on it.
            return EnvelopeStatus::Failed;
        }

        $codes = array_map(fn (array $s) => strtolower((string) ($s['status_code'] ?? '')), $this->signatures($request));

        if (($request['is_declined'] ?? false) === true || in_array('declined', $codes, true)) {
            return EnvelopeStatus::Declined;
        }
        if (($request['is_complete'] ?? false) === true && $codes !== [] && array_diff($codes, ['signed']) === []) {
            return EnvelopeStatus::Signed;
        }
        $expiresAt = $request['expires_at'] ?? null;
        if (in_array('expired', $codes, true) || (is_int($expiresAt) && $expiresAt > 0 && $expiresAt < time())) {
            return EnvelopeStatus::Expired;
        }

        foreach ($codes as $code) {
            // awaiting_signature and signed are documented; on_hold appears in the
            // "release on-hold signature request" docs (a request waiting to be released).
            if (! in_array($code, ['awaiting_signature', 'signed', 'on_hold'], true)) {
                throw new ProviderError('dropbox', "Dropbox Sign returned a signature status this driver does not know: \"{$code}\". Please report it.");
            }
        }

        return in_array('signed', $codes, true) ? EnvelopeStatus::PartiallySigned : EnvelopeStatus::Sent;
    }

    /**
     * The event JSON out of whichever body shape arrived.
     *
     * @param array<string, string|string[]> $headers
     */
    private function eventJson(array $headers, string $rawBody): string
    {
        $body = ltrim($rawBody);
        if (str_starts_with($body, '{')) {
            return $body;
        }

        // multipart/form-data: the event is the part named "json".
        $boundary = $this->boundary($headers) ?? (preg_match('/^--([^\r\n]+)\r?\n/', $body, $m) ? $m[1] : null);
        if ($boundary !== null && str_starts_with($body, '--' . $boundary)) {
            foreach (explode('--' . $boundary, $body) as $part) {
                [$head, $content] = array_pad(preg_split('/\r?\n\r?\n/', $part, 2) ?: [], 2, null);
                if (is_string($head) && is_string($content) && preg_match('/^content-disposition:.*\bname="?json"?(;|\s|$)/im', $head)) {
                    return preg_replace('/\r?\n$/', '', $content) ?? $content;
                }
            }
            throw new InvalidWebhook('Dropbox Sign event body has no "json" field.');
        }

        // application/x-www-form-urlencoded: json=...
        parse_str($rawBody, $form);
        if (is_string($form['json'] ?? null)) {
            return $form['json'];
        }

        throw new InvalidWebhook('Dropbox Sign event body is not JSON, a "json" form field, or multipart/form-data.');
    }

    /** @param array<string, string|string[]> $headers */
    private function boundary(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }
            foreach ((array) $values as $value) {
                if (preg_match('/boundary="?([^";]+)"?/i', (string) $value, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}
