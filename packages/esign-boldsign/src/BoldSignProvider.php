<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign;

use DateTimeInterface;
use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\ExtendsExpiry;
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

/**
 * BoldSign (boldsign.com), API v1, authenticated with an API key.
 *
 * Built from BoldSign's documentation (developers.boldsign.com), not yet run against a live
 * account. What the documentation says that shapes this driver:
 * - Sending is asynchronous: /v1/document/send answers 201 with a documentId right away, and the
 *   file is processed afterwards. A "SendFailed" webhook reports a send that failed later, and
 *   status() can answer 404 for a short while after send().
 * - Document status is "InProgress" until it is "Completed", "Declined", "Revoked" or "Expired".
 *   Signer status is "NotCompleted" or "Completed".
 * - Revoke needs a reason, and only works on documents that are not completed.
 * - Webhooks are signed: X-BoldSign-Signature: t=<unix time>, s0=<hex HMAC-SHA256 of "t.body">
 *   with the webhook's signing secret, plus s1 for the previous secret while it is still valid.
 * - Webhooks are set up per account or app in BoldSign, not per document, so the envelope's
 *   callbackUrl is not sent.
 */
final class BoldSignProvider implements SignatureProvider, HasCapabilities, ReportsSigners, SendsReminders, ExtendsExpiry, RecognizesWebhooks
{
    /** "You can upload up to 25 files" (https://developers.boldsign.com/documents/send-document/). */
    private const MAX_FILES = 25;

    /** Formats BoldSign accepts in Files. PDF is the one it prefers. */
    private const MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly BoldSignApi $api,
        private readonly BoldSignOptions $options = new BoldSignOptions(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function name(): string
    {
        return 'boldsign';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            identityMethods: [IdentityMethod::Email, IdentityMethod::SmsOtp],
            features: [
                Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement,
                Feature::Reminders, Feature::ExpiryExtension, Feature::SignerDetails,
                // No SigningUrls: send() returns no links (BoldSign emails them, and the document is
                // still processing when send() returns). signingLink() fetches one on demand.
                // No WebhookManagement: webhooks are set up in BoldSign's dashboard.
            ],
            maxDocuments: self::MAX_FILES,
        );
    }

    public function details(string $envelopeId): EnvelopeDetails
    {
        $data = $this->properties($envelopeId);
        $signers = array_map(fn (array $s) => new SignerState(
            is_string($s['signerEmail'] ?? null) ? $s['signerEmail'] : '',
            is_string($s['signerName'] ?? null) ? trim($s['signerName']) : '',
            match (strtolower(is_string($s['status'] ?? null) ? $s['status'] : '')) {
                'completed' => SignerStatus::Signed,
                'declined' => SignerStatus::Declined,
                // "NotCompleted" is documented. Anything else is reported as pending: it never claims a signature.
                default => SignerStatus::Pending,
            },
            null,     // links come by email; signingLink() fetches an embedded one
            is_int($s['order'] ?? null) ? $s['order'] : null,
        ), $this->signers($data));

        return new EnvelopeDetails(new EnvelopeRef('boldsign', $envelopeId), $this->map($data), $signers);
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        if (count($envelope->documents) > self::MAX_FILES) {
            throw new EsignException('BoldSign takes at most ' . self::MAX_FILES . ' files per document.');
        }
        // Everything is checked before the first HTTP call, so a bad envelope never half-sends.
        $files = array_map(fn (Document $d) => $this->file($d), $envelope->documents);
        $signers = array_map(fn (Signer $s) => $this->signer($s), $envelope->signers);
        $ordered = count(array_unique(array_map(fn (Signer $s) => $s->order, $envelope->signers))) > 1;

        $body = [
            'Title' => $envelope->subject,
            'Message' => $envelope->message,
            'Signers' => array_map(fn (array $s) => $ordered ? $s : array_diff_key($s, ['SignerOrder' => true]), $signers),
            'Files' => $files,
            'EnableSigningOrder' => $ordered,
        ];
        if ($this->options->expiryDays !== null) {
            $body['ExpiryDays'] = $this->options->expiryDays;
        }
        $metadata = $this->metadata($envelope->metadata);
        if ($metadata !== []) {
            $body['MetaData'] = $metadata;
        }

        $created = $this->api->post('/v1/document/send', [], $body);
        $documentId = is_array($created) ? ($created['documentId'] ?? null) : null;
        if (! is_string($documentId) || $documentId === '') {
            throw new ProviderError('boldsign', 'Document was sent but BoldSign returned no documentId.');
        }

        // Signing links come by email. For an embedded one, see signingLink().
        return new SentEnvelope('boldsign', $documentId, EnvelopeStatus::Sent);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        return $this->map($this->properties($envelopeId));
    }

    public function download(string $envelopeId): string
    {
        $status = $this->status($envelopeId);
        if ($status !== EnvelopeStatus::Signed) {
            throw new EsignException("BoldSign document {$envelopeId} is {$status->value}, not signed. There is no signed PDF yet.");
        }

        $pdf = $this->api->bytes('/v1/document/download', ['documentId' => $envelopeId]);
        if (! str_starts_with($pdf, '%PDF')) {
            throw new ProviderError('boldsign', "BoldSign returned something other than a PDF for document {$envelopeId}.");
        }

        return $pdf;
    }

    /** Revokes the document. BoldSign refuses to revoke a completed one. */
    public function cancel(string $envelopeId): void
    {
        $this->api->post('/v1/document/revoke', ['documentId' => $envelopeId], ['Message' => $this->options->revokeMessage]);
    }

    public function recognizesWebhook(array $headers, string $rawBody): bool
    {
        return Headers::has($headers, 'X-BoldSign-Signature') || Headers::has($headers, 'X-BoldSign-Event');
    }

    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        // BoldSign pings a new webhook URL with X-BoldSign-Event: Verification and expects a 200.
        // It names no document, so there is nothing to sync.
        if (strcasecmp($this->header($headers, 'X-BoldSign-Event') ?? '', 'Verification') === 0) {
            throw new InvalidWebhook('BoldSign webhook verification request: answer it with 200. It is about no document.');
        }

        $this->verify($headers, $rawBody);

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new InvalidWebhook('BoldSign webhook body is not JSON.');
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $documentId = $data['documentId'] ?? null;
        if (! is_string($documentId) || $documentId === '') {
            // Sender identity and template events are signed too, but are not about a document.
            throw new InvalidWebhook('BoldSign webhook is not about a document (no data.documentId).');
        }
        $eventId = is_array($payload['event'] ?? null) && is_string($payload['event']['id'] ?? null) ? $payload['event']['id'] : null;

        return new WebhookEvent('boldsign', $documentId, $eventId, $payload);
    }

    /**
     * Emails the signer a reminder (https://developers.boldsign.com/documents/send-reminder/):
     * POST /v1/document/remind?documentId=...&receiverEmails=... with {"Message": "..."}, answered 204.
     */
    public function remind(string $envelopeId, string $signerEmail): void
    {
        $this->api->post('/v1/document/remind', ['documentId' => $envelopeId, 'receiverEmails' => $signerEmail], ['Message' => $this->options->reminderMessage]);
    }

    /**
     * Moves the expiry date (https://developers.boldsign.com/documents/extend-document-expiry/):
     * PATCH /v1/document/extendExpiry?documentId=... with {"NewExpiryValue": "yyyy-MM-dd"}.
     * The date format is the one for documents whose expiry is set in days, which is how send()
     * sets it (ExpiryDays). BoldSign refuses dates more than 180 days after creation, earlier
     * dates, and signed documents.
     */
    public function extend(string $envelopeId, DateTimeInterface $until): void
    {
        $this->api->patch('/v1/document/extendExpiry', ['documentId' => $envelopeId], ['NewExpiryValue' => $until->format('Y-m-d')]);
    }

    // ---- BoldSign-only operations ------------------------------------------------------

    /**
     * A link that lets one signer sign inside your app (iframe, popup or new tab).
     * Verify the signer's identity yourself before you show it: BoldSign's audit trail records that
     * the document was signed through an embedded link.
     */
    public function signingLink(string $envelopeId, string $signerEmail, ?string $redirectUrl = null): string
    {
        $query = array_filter(['documentId' => $envelopeId, 'signerEmail' => $signerEmail, 'redirectUrl' => $redirectUrl], fn ($v) => $v !== null);
        $link = $this->api->get('/v1/document/getEmbeddedSignLink', $query)['signLink'] ?? null;

        return is_string($link) && $link !== '' ? $link : throw new ProviderError('boldsign', "BoldSign returned no signing link for document {$envelopeId}.");
    }

    // ---- internals ----------------------------------------------------------------------

    /** @return array{base64: string, fileName: string} */
    private function file(Document $document): array
    {
        if (! in_array($document->mimeType, self::MIME_TYPES, true)) {
            throw new EsignException("BoldSign accepts PDF, PNG, JPG, DOCX, XLSX and PPTX files; \"{$document->name}\" is {$document->mimeType}.");
        }

        // The documented shape for named files: [{"base64": "data:<type>;base64,<content>", "fileName": "..."}]
        return ['base64' => "data:{$document->mimeType};base64," . base64_encode($document->contents), 'fileName' => $document->name];
    }

    /** @return array<string, mixed> */
    private function signer(Signer $signer): array
    {
        $out = [
            'Name' => $signer->name,
            'EmailAddress' => $signer->email,
            'SignerType' => 'Signer',
            'SignerOrder' => max(1, $signer->order),
        ];

        switch ($signer->identity) {
            case IdentityMethod::Email:
                if ($this->options->emailOtp) {
                    $out['AuthenticationType'] = 'EmailOTP';
                }
                break;
            case IdentityMethod::SmsOtp:
                $out['AuthenticationType'] = 'SMSOTP';
                $out['PhoneNumber'] = $this->phone($signer);
                break;
            default:
                throw new EsignException("BoldSign does not support the \"{$signer->identity->value}\" identity method. Use email or sms_otp.");
        }

        if ($signer->fields !== []) {
            $out['FormFields'] = array_map(fn (SignatureField $f) => $this->field($signer, $f), $signer->fields);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function field(Signer $signer, SignatureField $field): array
    {
        if ($field->documentIndex !== 0) {
            // BoldSign's FormFields carry a page number but no file reference, and it is not
            // documented how pages are numbered across several files. Only the first file is safe.
            throw new EsignException("Signer \"{$signer->name}\" has a field on document {$field->documentIndex}; BoldSign fields can only be placed on the first document.");
        }
        if ($field->page < 1 || $field->width <= 0 || $field->height <= 0) {
            throw new EsignException("Signer \"{$signer->name}\" has a field with a page below 1 or a size of 0; BoldSign refuses both.");
        }
        $scale = $this->options->coordinateScale;

        return [
            'FieldType' => 'Signature',
            'PageNumber' => $field->page,
            'Bounds' => [
                'X' => round($field->x * $scale, 2),
                'Y' => round($field->y * $scale, 2),
                'Width' => round($field->width * $scale, 2),
                'Height' => round($field->height * $scale, 2),
            ],
            'IsRequired' => true,
        ];
    }

    /**
     * BoldSign wants the country code and the number apart ({"CountryCode": "+1", "Number": "2015666802"}).
     * Country codes are 1 to 3 digits and cannot be told apart from the number reliably without a
     * numbering plan, so the mobile must show where the country code ends: "+966 500000000".
     *
     * @return array{CountryCode: string, Number: string}
     */
    private function phone(Signer $signer): array
    {
        $mobile = trim((string) $signer->mobile);
        if (! preg_match('/^\+(\d{1,3})[\s\-.]+(\d[\d\s\-.]{3,})$/', $mobile, $m)) {
            throw new EsignException("Signer \"{$signer->name}\" needs a mobile like \"+966 500000000\" for SMS OTP: a + and the country code, a space, then the number.");
        }

        return ['CountryCode' => '+' . $m[1], 'Number' => (string) preg_replace('/\D/', '', $m[2])];
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, string>
     */
    private function metadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            // BoldSign's documented limits for MetaData.
            if (strlen((string) $key) > 50 || strlen($value) > 500) {
                throw new EsignException("BoldSign metadata keys are limited to 50 characters and values to 500; \"{$key}\" is too long.");
            }
        }

        return $metadata;
    }

    /** @return array<mixed> */
    private function properties(string $envelopeId): array
    {
        return $this->api->get('/v1/document/properties', ['documentId' => $envelopeId]);
    }

    /**
     * BoldSign's document status, checked against the signers. See the table in the README.
     *
     * @param array<mixed> $data
     */
    private function map(array $data): EnvelopeStatus
    {
        $raw = is_string($data['status'] ?? null) ? $data['status'] : '';

        return match (strtolower(trim($raw))) {
            // "Signing completed by all recipients". Still, a PDF is only handed out when every
            // listed signer says Completed too.
            'completed' => $this->everyoneSigned($data) ? EnvelopeStatus::Signed : $this->open($data),
            'declined' => EnvelopeStatus::Declined,
            'revoked' => EnvelopeStatus::Cancelled,
            'expired' => EnvelopeStatus::Expired,
            // InProgress is what a document reports. The others are BoldSign's in-progress list filters,
            // accepted in case a document reports one of them.
            'inprogress', 'waitingforothers', 'waitingforme', 'needattention', 'scheduled' => $this->open($data),
            default => throw new ProviderError('boldsign', "BoldSign returned a document status this driver does not know: \"{$raw}\". Please report it."),
        };
    }

    /** @param array<mixed> $data */
    private function open(array $data): EnvelopeStatus
    {
        foreach ($this->signers($data) as $signer) {
            if (strcasecmp((string) ($signer['status'] ?? ''), 'Completed') === 0) {
                return EnvelopeStatus::PartiallySigned;
            }
        }

        return EnvelopeStatus::Sent;
    }

    /** @param array<mixed> $data */
    private function everyoneSigned(array $data): bool
    {
        $signers = $this->signers($data);
        if ($signers === []) {
            // Nothing to check against; trust the document status.
            return true;
        }

        return array_filter($signers, fn (array $s) => strcasecmp((string) ($s['status'] ?? ''), 'Completed') !== 0) === [];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function signers(array $data): array
    {
        $signers = is_array($data['signerDetails'] ?? null) ? $data['signerDetails'] : [];

        return array_values(array_filter($signers, 'is_array'));
    }

    /** @param array<string, string|string[]> $headers */
    private function verify(array $headers, string $rawBody): void
    {
        $secret = (string) $this->options->webhookSecret;
        if ($secret === '') {
            throw new InvalidWebhook('BoldSign webhook_secret is not configured. Refusing the webhook.');
        }
        $header = $this->header($headers, 'X-BoldSign-Signature');
        if ($header === null || $header === '') {
            throw new InvalidWebhook('BoldSign webhook has no X-BoldSign-Signature header.');
        }

        // "t=1668693823, s0=9b7a..., s1=62a1..."
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$key, $value] = [trim($parts[0]), trim($parts[1])];
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 's0' || $key === 's1') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new InvalidWebhook('BoldSign webhook signature header is malformed.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            // Every candidate is compared, in constant time.
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (! $matched) {
            throw new InvalidWebhook('BoldSign webhook signature does not match.');
        }

        // Checked after the signature, so the timestamp is known to come from BoldSign.
        if (abs(($this->clock)() - $timestamp) > $this->options->webhookTolerance) {
            throw new InvalidWebhook("BoldSign webhook timestamp is more than {$this->options->webhookTolerance} seconds away from now. Refusing a possible replay.");
        }
    }

    /** @param array<string, string|string[]> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0) {
                $values = (array) $values;
                $first = reset($values);

                return $first === false ? null : (string) $first;
            }
        }

        return null;
    }
}
