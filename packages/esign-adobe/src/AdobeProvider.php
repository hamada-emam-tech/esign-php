<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe;

use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\CustomizesWebhookResponse;
use HamadaEmamTech\Esign\Contracts\Features\ManagesWebhooks;
use HamadaEmamTech\Esign\Contracts\Features\ReportsSigners;
use HamadaEmamTech\Esign\Contracts\Features\SendsReminders;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\Features\RecognizesWebhooks;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Support\Headers;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeDetails;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerState;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Support\PdfPageSize;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use HamadaEmamTech\Esign\Webhook\WebhookResponse;

/**
 * Adobe Acrobat Sign, REST API v6.
 *
 * Built from Adobe's published OpenAPI description and developer guides, not yet verified
 * against a live account. Choices made where the documentation leaves room:
 * - Each Signer becomes its own participant set with role SIGNER. (A set with several members is
 *   finished when ANY one of them acts, which is not what a list of signers means.)
 * - Signing orders are renumbered to 1, 2, 3...: Acrobat Sign requires "a valid consecutive
 *   increasing sequence". Signers that share an order stay together (parallel signing).
 * - With signature fields, the agreement is created in AUTHORING, the fields are written with
 *   PUT /formFields, then it moves to IN_PROCESS. Adobe: "Form fields can be added to the agreements
 *   only when it is in the AUTHORING state." Nobody is invited before the fields are in place.
 * - Without fields it is created straight in IN_PROCESS and Acrobat Sign places the signature block.
 * - A rejected agreement reports status CANCELLED; the agreement's events tell them apart
 *   (GET /agreements/{id}/events): REJECTED is Declined, AUTO_CANCELLED_CONVERSION_PROBLEM is
 *   Failed (Acrobat Sign could not process a document), anything else is Cancelled.
 * - Partially signed comes from GET /agreements/{id}/members: a SIGNER set with status COMPLETED.
 * - GET /combinedDocument also serves the unsigned PDF before everyone signed (the guide:
 *   "Downloads work before all parties sign"), so download() checks status first.
 * - Moving an open agreement's deadline (PUT /agreements/{id}) needs the whole AgreementInfo
 *   (fileInfos, participantSetsInfo...) sent back, so ExtendsExpiry is not offered.
 */
final class AdobeProvider implements SignatureProvider, HasCapabilities, ReportsSigners, SendsReminders, ManagesWebhooks, CustomizesWebhookResponse, RecognizesWebhooks
{
    /** The header Acrobat Sign sends on webhook calls, and that your response must echo back. */
    public const CLIENT_ID_HEADER = 'X-AdobeSign-ClientId';

    /** The JSON key that can carry the echo instead of the header: {"xAdobeSignClientId": "..."}. */
    public const CLIENT_ID_BODY_KEY = 'xAdobeSignClientId';

    /** The webhook events registerWebhook() subscribes to by default. */
    public const DEFAULT_WEBHOOK_EVENTS = [
        'AGREEMENT_ACTION_COMPLETED',
        'AGREEMENT_WORKFLOW_COMPLETED',
        'AGREEMENT_RECALLED',
        'AGREEMENT_REJECTED',
        'AGREEMENT_EXPIRED',
        'AGREEMENT_AUTO_CANCELLED_CONVERSION_PROBLEM',
    ];

    /** Agreement statuses (FetchedAgreementInfo.status) that mean "still with the participants". */
    private const OPEN = [
        'OUT_FOR_SIGNATURE', 'OUT_FOR_DELIVERY', 'OUT_FOR_ACCEPTANCE', 'OUT_FOR_FORM_FILLING',
        'OUT_FOR_APPROVAL', 'OUT_FOR_WITNESSING', 'AUTHORING', 'DRAFT', 'PREFILL',
        'DOCUMENTS_NOT_YET_PROCESSED', 'WAITING_FOR_FAXIN', 'WAITING_FOR_VERIFICATION',
        'WIDGET_WAITING_FOR_VERIFICATION', 'WAITING_FOR_NOTARIZATION', 'WAITING_FOR_REVISION', 'PAUSED',
    ];

    /** Agreement statuses that mean the workflow completed. This driver only creates SIGNER sets, so SIGNED is the usual one. */
    private const COMPLETED = ['SIGNED', 'APPROVED', 'ACCEPTED', 'DELIVERED', 'FORM_FILLED'];

    public function __construct(
        private readonly AdobeApi $api,
        private readonly AdobeOptions $options = new AdobeOptions(),
    ) {
    }

    public function name(): string
    {
        return 'adobe';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            identityMethods: [IdentityMethod::Email, IdentityMethod::SmsOtp],
            features: [
                Feature::MultipleDocuments,     // fileInfos are combined into one PDF
                Feature::SigningOrder, Feature::FieldPlacement, Feature::Reminders,
                Feature::WebhookManagement, Feature::SignerDetails,
                // No SigningUrls: Acrobat Sign emails the links. No ExpiryExtension: see the class comment.
            ],
            maxDocuments: null,
            asyncCancel: false,
        );
    }

    public function details(string $envelopeId): EnvelopeDetails
    {
        $status = $this->status($envelopeId);
        $declinedBy = $status === EnvelopeStatus::Declined ? $this->eventEmails($envelopeId, 'REJECTED') : [];
        $members = $this->api->get('/agreements/' . rawurlencode($envelopeId) . '/members');

        $signers = [];
        foreach ($this->signerSets($members) as $set) {
            foreach ((array) ($set['memberInfos'] ?? []) as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $email = (string) ($member['email'] ?? '');
                $signers[] = new SignerState(
                    $email,
                    trim((string) ($member['name'] ?? '')),
                    match (true) {
                        ($set['status'] ?? null) === 'COMPLETED' => SignerStatus::Signed,
                        in_array(strtolower($email), $declinedBy, true) => SignerStatus::Declined,
                        default => SignerStatus::Pending,
                    },
                    null,      // links are emailed; see send()
                    isset($set['order']) ? (int) $set['order'] : null,
                );
            }
        }
        usort($signers, fn (SignerState $a, SignerState $b) => ($a->order ?? 0) <=> ($b->order ?? 0));

        return new EnvelopeDetails(new EnvelopeRef('adobe', $envelopeId), $status, $signers);
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        // Everything that can be refused is checked before the first HTTP call.
        $sets = $this->participantSets($envelope->signers);
        $fields = $this->fields($envelope);

        $fileInfos = [];
        foreach ($envelope->documents as $document) {
            $fileInfos[] = ['transientDocumentId' => $this->api->uploadTransient($document)];
        }

        $agreement = array_filter([
            'fileInfos' => $fileInfos,
            'name' => $envelope->subject,
            'participantSetsInfo' => $sets,
            'signatureType' => 'ESIGN',
            'state' => $fields === [] ? 'IN_PROCESS' : 'AUTHORING',
            'message' => $envelope->message !== '' ? $envelope->message : null,
            // Visible to every participant through the API, so only your reference, never a secret.
            'externalId' => is_string($envelope->metadata['reference'] ?? null) && $envelope->metadata['reference'] !== '' ? ['id' => $envelope->metadata['reference']] : null,
            'expirationTime' => $this->options->expiresInDays === null ? null : gmdate('Y-m-d\TH:i:s\Z', time() + $this->options->expiresInDays * 86400),
        ], fn ($v) => $v !== null);

        $created = $this->api->post('/agreements', $agreement);
        $id = $created['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new ProviderError('adobe', 'Agreement was created without an id.');
        }

        if ($fields !== []) {
            try {
                $this->placeFields($id, $envelope->signers, $fields);
                $this->api->put('/agreements/' . rawurlencode($id) . '/state', ['state' => 'IN_PROCESS']);
            } catch (ProviderError $e) {
                // The agreement is still in AUTHORING: no invitation went out.
                throw new ProviderError('adobe', "Agreement {$id} was created but not sent, because placing the signature fields failed (nobody was invited): " . preg_replace('/^\[adobe\] /', '', $e->getMessage()), $e->httpStatus, $e->providerCode, $e);
            }
        }

        // Acrobat Sign emails the signing links. GET /agreements/{id}/signingUrls is not called here:
        // it documents 404 AGREEMENT_NOT_SIGNABLE / AGREEMENT_NOT_VISIBLE, and a send must not fail
        // after the invitations went out.
        return new SentEnvelope('adobe', $id, EnvelopeStatus::Sent);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        $agreement = $this->api->get('/agreements/' . rawurlencode($envelopeId));
        $raw = (string) ($agreement['status'] ?? '');

        return match (true) {
            in_array($raw, self::COMPLETED, true) => EnvelopeStatus::Signed,
            in_array($raw, self::OPEN, true) => $this->anyoneSigned($envelopeId) ? EnvelopeStatus::PartiallySigned : EnvelopeStatus::Sent,
            $raw === 'CANCELLED' => $this->whyCancelled($envelopeId),
            $raw === 'EXPIRED' => EnvelopeStatus::Expired,
            // ARCHIVED is a document uploaded as already signed; this driver never creates one.
            default => throw new ProviderError('adobe', "Acrobat Sign returned an agreement status this driver does not know: \"{$raw}\". Please report it."),
        };
    }

    public function download(string $envelopeId): string
    {
        $status = $this->status($envelopeId);
        if ($status !== EnvelopeStatus::Signed) {
            throw new EsignException("Acrobat Sign agreement {$envelopeId} is {$status->value}, not signed. There is no signed PDF yet.");
        }

        $pdf = $this->api->download('/agreements/' . rawurlencode($envelopeId) . '/combinedDocument');

        return str_starts_with($pdf, '%PDF') ? $pdf : throw new ProviderError('adobe', "Acrobat Sign returned something other than a PDF for agreement {$envelopeId}.");
    }

    public function cancel(string $envelopeId): void
    {
        $this->api->put('/agreements/' . rawurlencode($envelopeId) . '/state', [
            'state' => 'CANCELLED',
            // Default false in the API. The signers were invited, so tell them it's off.
            'agreementCancellationInfo' => ['notifyOthers' => true],
        ]);
    }

    public function recognizesWebhook(array $headers, string $rawBody): bool
    {
        return Headers::has($headers, self::CLIENT_ID_HEADER);
    }

    /**
     * Acrobat Sign does not sign webhook bodies. What it does send is X-AdobeSign-ClientId, the
     * client ID of the application that registered the webhook. That is compared, in constant time,
     * with webhook_client_id. Without webhook_client_id every webhook is refused.
     *
     * The body is only used for the agreement id: status() asks Acrobat Sign. The response must
     * echo the client ID (webhookResponse(), which Esign::handleWebhook() uses), or Acrobat Sign
     * counts the delivery as failed.
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $this->verifyClientId($headers);

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new InvalidWebhook('Acrobat Sign webhook body is not JSON.');
        }
        $id = is_array($payload['agreement'] ?? null) ? ($payload['agreement']['id'] ?? null) : null;
        if (! is_string($id) || $id === '') {
            throw new InvalidWebhook('Acrobat Sign webhook has no agreement.id (only agreement events are supported).');
        }
        $eventId = $payload['webhookNotificationId'] ?? null;

        return new WebhookEvent('adobe', $id, is_string($eventId) && $eventId !== '' ? $eventId : null, $payload);
    }

    /**
     * The answer Acrobat Sign requires, for the GET "verification of intent" it makes when a webhook is
     * registered and for every POST notification: a 2XX that echoes X-AdobeSign-ClientId, in the
     * response header and in the JSON body {"xAdobeSignClientId": "..."} (either is enough; both are sent).
     *
     * A client ID that is missing or not webhook_client_id gets a 403 without the echo. Adobe: "If the
     * webhook URL does not recognize that client id, it MUST NOT respond with the success response
     * code." Without webhook_client_id every call gets the 403 (fail closed).
     */
    public function webhookResponse(array $headers): WebhookResponse
    {
        try {
            $clientId = $this->verifyClientId($headers);
        } catch (InvalidWebhook) {
            return new WebhookResponse(403);
        }

        return new WebhookResponse(
            200,
            (string) json_encode([self::CLIENT_ID_BODY_KEY => $clientId]),
            [self::CLIENT_ID_HEADER => $clientId, 'Content-Type' => 'application/json'],
        );
    }

    // ---- Acrobat Sign-only operations ----------------------------------------------

    /**
     * Register a webhook (POST /webhooks) for the agreement events this driver cares about, with
     * scope USER (the token's user; ACCOUNT needs an admin token).
     *
     * Acrobat Sign first sends $url a GET carrying X-AdobeSign-ClientId and creates the webhook only if
     * that call answers 2XX with the echo, so deploy the endpoint (handleWebhook) first. Acrobat Sign has
     * no shared webhook secret: verification is the client ID, so $secret must be null.
     */
    public function registerWebhook(string $url, #[\SensitiveParameter] ?string $secret = null): string
    {
        if ($secret !== null) {
            throw new EsignException('Acrobat Sign webhooks have no shared secret; they are verified by X-AdobeSign-ClientId. Set webhook_client_id and pass no secret.');
        }
        $created = $this->api->post('/webhooks', [
            'name' => 'esign',
            'scope' => 'USER',
            'state' => 'ACTIVE',
            'webhookSubscriptionEvents' => self::DEFAULT_WEBHOOK_EVENTS,
            'webhookUrlInfo' => ['url' => $url],
        ]);
        $id = $created['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : throw new ProviderError('adobe', 'Webhook was registered without an id.');
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->api->delete('/webhooks/' . rawurlencode($webhookId));
    }

    /** The first page of GET /webhooks (Acrobat Sign pages the list). */
    public function webhooks(): array
    {
        $list = $this->api->get('/webhooks');
        $out = [];
        foreach ((array) ($list['userWebhookList'] ?? []) as $hook) {
            if (is_array($hook) && is_string($hook['id'] ?? null)) {
                $out[] = ['id' => $hook['id'], 'url' => is_array($hook['webhookUrlInfo'] ?? null) ? (string) ($hook['webhookUrlInfo']['url'] ?? '') : ''];
            }
        }

        return $out;
    }

    /** Email one signer a reminder (POST /agreements/{id}/reminders). It does not add the signer again. */
    public function remind(string $envelopeId, string $signerEmail): void
    {
        $members = $this->api->get('/agreements/' . rawurlencode($envelopeId) . '/members');
        $ids = [];
        foreach ($this->signerSets($members) as $set) {
            foreach ((array) ($set['memberInfos'] ?? []) as $member) {
                if (is_array($member) && is_string($member['id'] ?? null) && strcasecmp((string) ($member['email'] ?? ''), $signerEmail) === 0) {
                    $ids[] = $member['id'];
                }
            }
        }
        if ($ids === []) {
            throw new EsignException("{$signerEmail} is not a signer of Acrobat Sign agreement {$envelopeId}.");
        }
        $this->api->post('/agreements/' . rawurlencode($envelopeId) . '/reminders', ['recipientParticipantIds' => $ids, 'status' => 'ACTIVE']);
    }

    // ---- internals ------------------------------------------------------------------

    /**
     * @param list<Signer> $signers
     *
     * @return list<array<string, mixed>>
     */
    private function participantSets(array $signers): array
    {
        // Acrobat Sign wants 1, 2, 3...; keep the relative order and the ties.
        $orders = array_values(array_unique(array_map(fn (Signer $s) => $s->order, $signers)));
        sort($orders);
        $dense = array_flip($orders);

        return array_map(fn (Signer $s) => [
            'memberInfos' => [[
                'email' => $s->email,
                'name' => $s->name,
                'securityOption' => $this->securityOption($s),
            ]],
            'order' => $dense[$s->order] + 1,
            'role' => 'SIGNER',
        ], $signers);
    }

    /** @return array<string, mixed> ParticipantSecurityOption */
    private function securityOption(Signer $signer): array
    {
        return match ($signer->identity) {
            IdentityMethod::Email => ['authenticationMethod' => 'NONE'],
            IdentityMethod::SmsOtp => ['authenticationMethod' => 'PHONE', 'phoneInfo' => $this->phone($signer)],
            default => throw new EsignException("Acrobat Sign does not support the \"{$signer->identity->value}\" identity method. Use email or sms_otp."),
        };
    }

    /**
     * PhoneInfo takes the calling code and the number separately, and splitting an E.164 number needs
     * a country table. So the mobile must show the split: "+966 500000000" or "+1-4155550100".
     *
     * @return array{countryCode: string, phone: string}
     */
    private function phone(Signer $signer): array
    {
        if (preg_match('/^\+(\d{1,4})[\s-]+(\d[\d\s-]{3,})$/', trim((string) $signer->mobile), $m) !== 1) {
            throw new EsignException("Signer \"{$signer->name}\" needs a mobile like \"+966 500000000\" (country code, a space, the number) for Acrobat Sign phone authentication.");
        }

        return ['countryCode' => '+' . $m[1], 'phone' => (string) preg_replace('/\D/', '', $m[2])];
    }

    /**
     * Converts every SignatureField to a FormFieldLocation, before anything is uploaded.
     *
     * @return array<int, list<array{pageNumber: int, top: float, left: float, width: float, height: float}>> signer index => locations
     */
    private function fields(Envelope $envelope): array
    {
        $withFields = array_filter($envelope->signers, fn (Signer $s) => $s->fields !== []);
        if ($withFields === []) {
            return [];
        }
        if (count($withFields) !== count($envelope->signers)) {
            // Acrobat Sign only adds its own signature block when there are no fields at all; a signer
            // without a field next to signers with fields might get nothing to sign.
            throw new EsignException('Acrobat Sign: give every signer a signature field, or none of them (then Acrobat Sign places the signature block).');
        }

        $first = $envelope->documents[0];
        $height = null;
        $locations = [];
        foreach ($envelope->signers as $i => $signer) {
            foreach ($signer->fields as $field) {
                if ($field->documentIndex !== 0) {
                    // Acrobat Sign combines all files into one PDF and numbers its pages; the page count of
                    // the earlier files is not known here, so a field can only target the first document.
                    throw new EsignException("Signer \"{$signer->name}\" has a field on document {$field->documentIndex}. With Acrobat Sign, put signature fields on the first document.");
                }
                $height ??= ($first->mimeType === 'application/pdf' ? PdfPageSize::uniformHeight($first->contents) : null)
                    ?? $this->options->pageHeight
                    ?? throw new EsignException("Cannot read one page height from \"{$first->name}\" (mixed page sizes, rotated or compressed pages, or not a PDF). Set the Acrobat Sign option page_height to place signature fields.");
                if ($field->page < 1 || $field->y < 0 || $field->y > $height || $field->x < 0) {
                    throw new EsignException("Signer \"{$signer->name}\" has a field outside the page (page {$field->page}, x {$field->x}, y {$field->y}, page height {$height}).");
                }
                $locations[$i][] = [
                    'pageNumber' => $field->page,
                    // SignatureField: y of the field's top edge, from the top. Acrobat Sign: from the bottom.
                    'top' => round($height - $field->y, 2),
                    'left' => round($field->x, 2),
                    'width' => round($field->width, 2),
                    'height' => round($field->height, 2),
                ];
            }
        }

        return $locations;
    }

    /**
     * @param list<Signer> $signers
     * @param array<int, list<array<string, int|float>>> $locations
     */
    private function placeFields(string $agreementId, array $signers, array $locations): void
    {
        $members = $this->api->get('/agreements/' . rawurlencode($agreementId) . '/members');

        // Participant set ids are assigned by Acrobat Sign; match them back by email, each set used once.
        $setIds = [];
        foreach ($this->signerSets($members) as $set) {
            foreach ((array) ($set['memberInfos'] ?? []) as $member) {
                if (is_array($member) && is_string($set['id'] ?? null)) {
                    $setIds[strtolower((string) ($member['email'] ?? ''))][] = $set['id'];
                }
            }
        }

        $fields = [];
        foreach ($signers as $i => $signer) {
            $email = strtolower($signer->email);
            $setId = isset($setIds[$email]) ? array_shift($setIds[$email]) : null;
            if ($setId === null) {
                throw new ProviderError('adobe', "Agreement {$agreementId} has no participant set for {$signer->email}.");
            }
            foreach ($locations[$i] ?? [] as $n => $location) {
                $fields[] = [
                    'name' => 'esign_signature_' . ($i + 1) . '_' . ($n + 1),
                    'inputType' => 'SIGNATURE',
                    'contentType' => 'SIGNATURE',
                    'required' => true,
                    'assignee' => $setId,
                    'locations' => [$location],
                ];
            }
        }

        $this->api->put('/agreements/' . rawurlencode($agreementId) . '/formFields', ['fields' => $fields]);
    }

    private function anyoneSigned(string $agreementId): bool
    {
        $members = $this->api->get('/agreements/' . rawurlencode($agreementId) . '/members');
        foreach ($this->signerSets($members) as $set) {
            if (($set['status'] ?? null) === 'COMPLETED') {
                return true;
            }
        }

        return false;
    }

    /** CANCELLED covers a cancel, a signer's rejection and an automatic cancel; the events say which. */
    private function whyCancelled(string $agreementId): EnvelopeStatus
    {
        $types = array_map(fn (array $e) => (string) ($e['type'] ?? ''), $this->events($agreementId));

        return match (true) {
            in_array('REJECTED', $types, true) => EnvelopeStatus::Declined,
            in_array('AUTO_CANCELLED_CONVERSION_PROBLEM', $types, true) => EnvelopeStatus::Failed,
            default => EnvelopeStatus::Cancelled,
        };
    }

    /** @return list<array<mixed>> */
    private function events(string $agreementId): array
    {
        $events = $this->api->get('/agreements/' . rawurlencode($agreementId) . '/events');

        return array_values(array_filter((array) ($events['events'] ?? []), 'is_array'));
    }

    /** @return list<string> lower-cased participantEmail of every event of $type */
    private function eventEmails(string $agreementId, string $type): array
    {
        $emails = [];
        foreach ($this->events($agreementId) as $event) {
            if (($event['type'] ?? null) === $type && is_string($event['participantEmail'] ?? null)) {
                $emails[] = strtolower($event['participantEmail']);
            }
        }

        return $emails;
    }

    /**
     * The participant sets you created (role SIGNER), from a MembersInfo response.
     *
     * @param array<mixed> $members
     *
     * @return list<array<mixed>>
     */
    private function signerSets(array $members): array
    {
        $sets = is_array($members['participantSets'] ?? null) ? $members['participantSets'] : [];

        return array_values(array_filter($sets, fn ($s) => is_array($s) && ($s['role'] ?? null) === 'SIGNER'));
    }

    /**
     * @param array<string, string|string[]> $headers
     *
     * @return string the verified client ID
     */
    private function verifyClientId(array $headers): string
    {
        $expected = (string) $this->options->webhookClientId;
        if ($expected === '') {
            throw new InvalidWebhook('No Acrobat Sign webhook_client_id is configured. Refusing the webhook.');
        }

        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, self::CLIENT_ID_HEADER) !== 0) {
                continue;
            }
            foreach ((array) $values as $value) {
                if (hash_equals($expected, trim((string) $value))) {
                    return $expected;
                }
            }
        }

        throw new InvalidWebhook('Acrobat Sign webhook ' . self::CLIENT_ID_HEADER . ' is missing or does not match webhook_client_id.');
    }
}
