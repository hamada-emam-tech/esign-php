<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign\Tests\Unit;

use HamadaEmamTech\Esign\Capabilities\EnvelopeValidator;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\DocuSign\DocuSignOptions;
use HamadaEmamTech\Esign\DocuSign\Tests\Support\ArrayCache;
use HamadaEmamTech\Esign\DocuSign\Tests\Support\FakeDocuSign;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Exceptions\EnvelopeNotSupported;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignProviderTest extends TestCase
{
    private const ENVELOPES = '/restapi/v2.1/accounts/' . FakeDocuSign::ACCOUNT_ID . '/envelopes';

    private FakeDocuSign $docusign;

    protected function setUp(): void
    {
        $this->docusign = new FakeDocuSign();
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope(
            [new Document('contract.pdf', FakeDocuSign::ORIGINAL), new Document('annex.docx', 'PK docx bytes', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
            $signers === [] ? [
                Signer::email('Ahmed Ali', 'ahmed@example.sa', 1, [new SignatureField(1, 60.4, 700.6), new SignatureField(2, 100, 120, documentIndex: 1)]),
                Signer::email('Sara', 'sara@example.sa', 2),
            ] : array_values($signers),
            subject: 'Please sign',
            message: 'Two documents attached.',
            callbackUrl: 'https://app.example.sa/webhooks/docusign',
            metadata: ['reference' => 'contract-42'],
        );
    }

    /**
     * @param array<string, string|string[]> $extra
     *
     * @return array<string, string|string[]>
     */
    private static function signed(string $body, string $secret = 'hmac-key-1', array $extra = []): array
    {
        return ['Content-Type' => 'application/json', 'X-DocuSign-Signature-1' => base64_encode(hash_hmac('sha256', $body, $secret, true))] + $extra;
    }

    private static function connectMessage(string $envelopeId = 'env-9'): string
    {
        // The JSON SIM shape from DocuSign's Connect 2.0 docs, line ends included.
        return "{\r\n  \"event\": \"envelope-completed\",\r\n  \"apiVersion\": \"v2.1\",\r\n  \"uri\": \"/restapi/v2.1/accounts/" . FakeDocuSign::ACCOUNT_ID . "/envelopes/{$envelopeId}\",\r\n"
            . "  \"retryCount\": 0,\r\n  \"configurationId\": 10242474,\r\n  \"generatedDateTime\": \"2026-10-18T10:00:00.0000000Z\",\r\n"
            . "  \"data\": {\r\n    \"accountId\": \"" . FakeDocuSign::ACCOUNT_ID . "\",\r\n    \"userId\": \"" . FakeDocuSign::USER_ID . "\",\r\n    \"envelopeId\": \"{$envelopeId}\"\r\n  }\r\n}";
    }

    // ---- send ----------------------------------------------------------------------

    #[Test]
    public function send_creates_and_sends_the_envelope_in_one_call(): void
    {
        $sent = $this->docusign->provider()->send($this->envelope());

        self::assertSame('docusign', $sent->provider);
        self::assertSame('00000000-0000-0000-0000-000000000001', $sent->envelopeId);
        self::assertSame(EnvelopeStatus::Sent, $sent->status);
        self::assertSame([], $sent->signingUrls);      // remote signers: DocuSign emails the links

        $body = $this->docusign->lastBody('POST', self::ENVELOPES);
        self::assertNotNull($body);
        self::assertSame('sent', $body['status']);
        self::assertSame('Please sign', $body['emailSubject']);
        self::assertSame('Two documents attached.', $body['emailBlurb']);
        self::assertSame([['name' => 'reference', 'value' => 'contract-42', 'show' => 'false']], $body['customFields']['textCustomFields']);
    }

    #[Test]
    public function documents_are_base64_with_ids_and_extensions(): void
    {
        $this->docusign->provider()->send($this->envelope());
        $documents = $this->docusign->lastBody('POST', self::ENVELOPES)['documents'] ?? null;

        self::assertSame([
            ['documentId' => '1', 'name' => 'contract.pdf', 'fileExtension' => 'pdf', 'documentBase64' => base64_encode(FakeDocuSign::ORIGINAL)],
            ['documentId' => '2', 'name' => 'annex.docx', 'fileExtension' => 'docx', 'documentBase64' => base64_encode('PK docx bytes')],
        ], $documents);
    }

    #[Test]
    public function signers_get_recipient_ids_routing_order_and_sign_here_tabs(): void
    {
        $this->docusign->provider()->send($this->envelope());
        [$ahmed, $sara] = $this->docusign->lastBody('POST', self::ENVELOPES)['recipients']['signers'] ?? [];

        self::assertSame('1', $ahmed['recipientId']);
        self::assertSame('1', $ahmed['routingOrder']);
        self::assertSame('Ahmed Ali', $ahmed['name']);
        self::assertSame('ahmed@example.sa', $ahmed['email']);
        self::assertSame([
            ['documentId' => '1', 'recipientId' => '1', 'pageNumber' => '1', 'xPosition' => '60', 'yPosition' => '701'],
            ['documentId' => '2', 'recipientId' => '1', 'pageNumber' => '2', 'xPosition' => '100', 'yPosition' => '120'],
        ], $ahmed['tabs']['signHereTabs']);
        self::assertArrayNotHasKey('identityVerification', $ahmed);

        self::assertSame('2', $sara['recipientId']);
        self::assertSame('2', $sara['routingOrder']);
        self::assertArrayNotHasKey('tabs', $sara);
    }

    #[Test]
    public function signers_with_the_same_order_sign_in_parallel_and_order_zero_becomes_one(): void
    {
        $this->docusign->provider()->send($this->envelope(Signer::email('A', 'a@example.sa', 0), Signer::email('B', 'b@example.sa', 0)));
        $signers = $this->docusign->lastBody('POST', self::ENVELOPES)['recipients']['signers'] ?? [];

        self::assertSame(['1', '1'], array_column($signers, 'routingOrder'));
        self::assertSame(['1', '2'], array_column($signers, 'recipientId'));
    }

    #[Test]
    public function a_callback_url_subscribes_the_envelope_to_signed_json_connect_messages(): void
    {
        $this->docusign->provider()->send($this->envelope());
        $notification = $this->docusign->lastBody('POST', self::ENVELOPES)['eventNotification'] ?? [];

        self::assertSame('https://app.example.sa/webhooks/docusign', $notification['url']);
        self::assertSame('SIM', $notification['deliveryMode']);
        self::assertSame(['version' => 'restv2.1'], $notification['eventData']);
        self::assertSame('true', $notification['includeHMAC']);
        self::assertContains('envelope-completed', $notification['events']);
    }

    #[Test]
    public function an_unsupported_identity_method_is_refused_before_any_http_call(): void
    {
        try {
            $this->docusign->provider()->send($this->envelope(Signer::nafath('1012345678', 'Omar', 'omar@example.sa')));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('"nafath"', $e->getMessage());
        }
        self::assertSame([], $this->docusign->calls);       // not even a token request
    }

    #[Test]
    public function sms_otp_uses_the_phone_authentication_workflow(): void
    {
        $provider = $this->docusign->provider(new DocuSignOptions(['hmac-key-1'], phoneAuthWorkflowId: 'workflow-phone'));
        $provider->send($this->envelope(new Signer('Omar', 'omar@example.sa', IdentityMethod::SmsOtp, mobile: '+966 50-123-4567')));
        $signer = $this->docusign->lastBody('POST', self::ENVELOPES)['recipients']['signers'][0] ?? [];

        self::assertSame([
            'workflowId' => 'workflow-phone',
            'inputOptions' => [[
                'name' => 'phone_number_list',
                'valueType' => 'PhoneNumberList',
                'phoneNumberList' => [['countryCode' => '966', 'number' => '501234567']],
            ]],
        ], $signer['identityVerification']);
    }

    #[Test]
    public function sms_otp_without_a_workflow_or_a_splittable_number_is_refused_before_any_http_call(): void
    {
        $sms = new Signer('Omar', 'omar@example.sa', IdentityMethod::SmsOtp, mobile: '+966501234567');

        foreach ([[new DocuSignOptions(['k']), 'phone_auth_workflow_id'], [new DocuSignOptions(['k'], phoneAuthWorkflowId: 'wf'), 'country code, a space']] as [$options, $expected]) {
            try {
                $this->docusign->provider($options)->send($this->envelope($sms));
                self::fail('Expected an exception');
            } catch (EsignException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
        self::assertSame([], $this->docusign->calls);
    }

    #[Test]
    public function a_field_on_a_missing_document_is_refused_before_any_http_call(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('field on document 5');
        try {
            $this->docusign->provider()->send($this->envelope(Signer::email('A', 'a@example.sa', 1, [new SignatureField(1, 1, 1, documentIndex: 5)])));
        } finally {
            self::assertSame([], $this->docusign->calls);
        }
    }

    #[Test]
    public function a_subject_longer_than_docusign_allows_is_refused_early(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('100 characters');
        $this->docusign->provider()->send(new Envelope([new Document('a.pdf', '%PDF')], [Signer::email('A', 'a@example.sa')], str_repeat('x', 101)));
    }

    // ---- status --------------------------------------------------------------------

    #[Test]
    public function status_follows_the_envelope_and_its_signers(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        self::assertStringEndsWith('?include=recipients', $this->docusign->calls[array_key_last($this->docusign->calls)]['url']);

        $this->docusign->sign($id, 'ahmed@example.sa');         // envelope "delivered", one signer "completed"
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));

        $this->docusign->processing($id);                        // envelope "signed": still processing
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));

        $this->docusign->signAll($id);                           // envelope "completed"
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function declined_voided_and_expired_are_told_apart(): void
    {
        $provider = $this->docusign->provider();

        $declined = $provider->send($this->envelope())->envelopeId;
        $this->docusign->decline($declined, 'sara@example.sa');
        self::assertSame(EnvelopeStatus::Declined, $provider->status($declined));

        $voided = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($voided);
        self::assertSame(EnvelopeStatus::Cancelled, $provider->status($voided));

        $expired = $provider->send($this->envelope())->envelopeId;
        $this->docusign->expire($expired);
        self::assertSame(EnvelopeStatus::Expired, $provider->status($expired));
    }

    #[Test]
    public function every_documented_envelope_status_has_a_mapping(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $expected = [
            'created' => EnvelopeStatus::Sent, 'sent' => EnvelopeStatus::Sent, 'delivered' => EnvelopeStatus::Sent,
            'correct' => EnvelopeStatus::Sent, 'signed' => EnvelopeStatus::PartiallySigned, 'completed' => EnvelopeStatus::Signed,
            'declined' => EnvelopeStatus::Declined, 'voided' => EnvelopeStatus::Cancelled,
            'timedout' => EnvelopeStatus::Expired, 'deleted' => EnvelopeStatus::Cancelled,
        ];

        foreach ($expected as $docusign => $status) {
            $this->docusign->forcedStatus = $docusign;
            self::assertSame($status, $provider->status($id), $docusign);
        }
    }

    #[Test]
    public function an_unknown_status_fails_loudly_instead_of_guessing(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->forcedStatus = 'template';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('"template"');
        $provider->status($id);
    }

    #[Test]
    public function an_unknown_envelope_carries_docusigns_error_code(): void
    {
        try {
            $this->docusign->provider()->status('does-not-exist');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('ENVELOPE_DOES_NOT_EXIST', $e->providerCode);
            self::assertStringStartsWith('[docusign] GET /envelopes/does-not-exist:', $e->getMessage());
        }
    }

    // ---- download ------------------------------------------------------------------

    #[Test]
    public function download_returns_the_combined_pdf_once_completed(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->signAll($id);

        self::assertSame(FakeDocuSign::SIGNED, $provider->download($id));
        self::assertContains(FakeDocuSign::BASE_URI . self::ENVELOPES . "/{$id}/documents/combined", $this->docusign->paths());
    }

    #[Test]
    public function download_refuses_before_completion_even_though_docusign_would_serve_a_pdf(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        foreach (['partially_signed' => fn () => $this->docusign->sign($id, 'ahmed@example.sa'), 'partially_signed ' => fn () => $this->docusign->processing($id)] as $expected => $step) {
            $step();
            try {
                $provider->download($id);
                self::fail('Expected an exception');
            } catch (EsignException $e) {
                self::assertStringContainsString(trim($expected), $e->getMessage());
            }
        }
        self::assertNotContains(FakeDocuSign::BASE_URI . self::ENVELOPES . "/{$id}/documents/combined", $this->docusign->paths());
    }

    #[Test]
    public function download_after_status_does_not_poll_the_envelope_again(): void
    {
        // DocuSign allows one status GET per envelope per 15 minutes; "completed" is final, so it is remembered.
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
        $before = $this->docusign->restCalls();

        $provider->download($id);

        self::assertSame($before + 1, $this->docusign->restCalls());
    }

    // ---- cancel --------------------------------------------------------------------

    #[Test]
    public function cancel_voids_the_envelope_with_a_reason(): void
    {
        $provider = $this->docusign->provider(new DocuSignOptions(['k'], voidReason: 'Contract withdrawn.'));
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        self::assertSame(['status' => 'voided', 'voidedReason' => 'Contract withdrawn.'], $this->docusign->lastBody('PUT', self::ENVELOPES . "/{$id}"));
    }

    #[Test]
    public function cancelling_a_finished_envelope_surfaces_docusigns_error(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->signAll($id);

        try {
            $provider->cancel($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('ENVELOPE_CANNOT_VOID_INVALID_STATE', $e->providerCode);
            self::assertSame(400, $e->httpStatus);
        }
    }

    #[Test]
    public function void_needs_a_reason(): void
    {
        $this->expectException(EsignException::class);
        $this->docusign->provider()->void('env-1', ' ');
    }

    // ---- authentication --------------------------------------------------------------

    #[Test]
    public function the_jwt_is_rs256_signed_with_the_documented_claims(): void
    {
        $now = 1_800_000_000;
        $this->docusign->provider(clock: fn () => $now)->send($this->envelope());

        // FakeDocuSign only accepts a JWT whose signature verifies with the public key.
        self::assertSame(1, $this->docusign->tokenCalls);
        self::assertSame(['typ' => 'JWT', 'alg' => 'RS256'], $this->docusign->lastJwtHeader);
        self::assertSame([
            'iss' => FakeDocuSign::INTEGRATION_KEY,
            'sub' => FakeDocuSign::USER_ID,
            'aud' => 'account-d.docusign.com',
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => 'signature impersonation',
        ], $this->docusign->lastClaims);
    }

    #[Test]
    public function a_jwt_signed_with_another_key_is_rejected_without_leaking_anything(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($other);
        openssl_pkey_export($other, $pem);
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $api = new \HamadaEmamTech\Esign\DocuSign\DocuSignApi($this->docusign, $factory, $factory, new \HamadaEmamTech\Esign\DocuSign\DocuSignCredentials(FakeDocuSign::INTEGRATION_KEY, FakeDocuSign::USER_ID, (string) $pem), FakeDocuSign::AUTH_HOST);

        try {
            (new \HamadaEmamTech\Esign\DocuSign\DocuSignProvider($api))->status('env-1');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('invalid_grant', $e->providerCode);
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('no_valid_keys_or_signatures', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            self::assertStringNotContainsString('eyJ', $e->getMessage());     // no JWT
        }
    }

    #[Test]
    public function missing_consent_is_explained(): void
    {
        $this->docusign->consentMissing = true;

        try {
            $this->docusign->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('consent_required', $e->providerCode);
            self::assertStringContainsString('signature impersonation', $e->getMessage());
        }
    }

    #[Test]
    public function an_auth_outage_is_reported_as_an_outage(): void
    {
        $this->docusign->authOutage = true;

        try {
            $this->docusign->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertStringContainsString('unavailable', $e->getMessage());
        }
    }

    #[Test]
    public function the_token_and_account_are_fetched_once_and_reused(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->status($id);
        $provider->cancel($id);

        self::assertSame(1, $this->docusign->tokenCalls);
        self::assertSame(1, $this->docusign->userInfoCalls);
    }

    #[Test]
    public function the_token_is_refreshed_15_minutes_before_it_expires(): void
    {
        $now = 1_800_000_000;
        $provider = $this->docusign->provider(clock: function () use (&$now) {
            return $now;
        });
        $id = $provider->send($this->envelope())->envelopeId;

        $now += 3600 - 901;
        $provider->status($id);
        self::assertSame(1, $this->docusign->tokenCalls);

        $now += 2;     // inside the 15-minute margin
        $provider->status($id);
        self::assertSame(2, $this->docusign->tokenCalls);
    }

    #[Test]
    public function a_psr16_cache_shares_the_token_and_account_between_instances(): void
    {
        $cache = new ArrayCache();
        $id = $this->docusign->provider(cache: $cache)->send($this->envelope())->envelopeId;
        $this->docusign->provider(cache: $cache)->status($id);

        self::assertSame(1, $this->docusign->tokenCalls);
        self::assertSame(1, $this->docusign->userInfoCalls);
        foreach ($cache->items as $key => $value) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);      // PSR-16 safe keys
        }
    }

    #[Test]
    public function a_401_gets_a_fresh_token_and_one_retry(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->unauthorizedNext = 1;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        self::assertSame(2, $this->docusign->tokenCalls);
    }

    #[Test]
    public function a_second_401_is_not_retried_forever(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->unauthorizedNext = 2;

        try {
            $provider->status($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('AUTHORIZATION_INVALID_TOKEN', $e->providerCode);
        }
        self::assertSame(2, $this->docusign->tokenCalls);
    }

    #[Test]
    public function the_configured_account_is_used_instead_of_the_default(): void
    {
        try {
            // The fake only serves ACCOUNT_ID; picking the other account proves account_id is honoured.
            $this->docusign->provider(accountId: FakeDocuSign::OTHER_ACCOUNT_ID)->status('env-1');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('USER_DOES_NOT_BELONG_TO_SPECIFIED_ACCOUNT', $e->providerCode);
            self::assertStringStartsWith('https://eu.docusign.net/restapi/v2.1/accounts/' . FakeDocuSign::OTHER_ACCOUNT_ID, $this->docusign->calls[array_key_last($this->docusign->calls)]['url']);
        }
    }

    #[Test]
    public function an_account_the_user_cannot_access_is_refused(): void
    {
        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('no access to the configured account_id');
        $this->docusign->provider(accountId: 'not-my-account')->status('env-1');
    }

    #[Test]
    public function a_configured_base_uri_skips_userinfo(): void
    {
        $provider = $this->docusign->provider(accountId: FakeDocuSign::ACCOUNT_ID, baseUri: FakeDocuSign::BASE_URI . '/');
        $provider->send($this->envelope());

        self::assertSame(0, $this->docusign->userInfoCalls);
    }

    #[Test]
    public function errors_never_carry_credentials(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->signAll($id);

        try {
            $provider->cancel($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            foreach (['token-1', 'PRIVATE KEY', FakeDocuSign::INTEGRATION_KEY, FakeDocuSign::USER_ID, FakeDocuSign::ACCOUNT_ID] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    // ---- capabilities ----------------------------------------------------------------

    #[Test]
    public function capabilities_declare_what_docusign_does(): void
    {
        $capabilities = $this->docusign->provider()->capabilities();

        self::assertSame([IdentityMethod::Email], $capabilities->identityMethods);
        self::assertNull($capabilities->maxDocuments);
        self::assertFalse($capabilities->asyncCancel);
        foreach ([Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement, Feature::Reminders, Feature::ExpiryExtension, Feature::SignerDetails] as $feature) {
            self::assertTrue($capabilities->supports($feature), $feature->value);
        }
        self::assertFalse($capabilities->supports(Feature::SigningUrls));
        self::assertFalse($capabilities->supports(Feature::WebhookManagement));
    }

    #[Test]
    public function sms_is_only_declared_when_the_phone_workflow_is_configured(): void
    {
        $capabilities = $this->docusign->provider(new DocuSignOptions(['k'], phoneAuthWorkflowId: 'wf'))->capabilities();

        self::assertSame([IdentityMethod::Email, IdentityMethod::SmsOtp], $capabilities->identityMethods);
    }

    #[Test]
    public function the_core_validator_refuses_saudi_and_uae_identities_for_docusign(): void
    {
        $capabilities = $this->docusign->provider()->capabilities();

        try {
            EnvelopeValidator::check($this->envelope(Signer::nafath('1012345678', 'Omar', 'omar@example.sa'), new Signer('Mona', 'mona@example.ae', IdentityMethod::UaePass)), $capabilities, 'docusign');
            self::fail('Expected an exception');
        } catch (EnvelopeNotSupported $e) {
            self::assertStringContainsString('"nafath"', $e->getMessage());
            self::assertStringContainsString('"uae_pass"', $e->getMessage());
        }
        foreach ([IdentityMethod::Absher, IdentityMethod::Wathq, IdentityMethod::UaePass] as $method) {
            try {
                $this->docusign->provider()->send($this->envelope(new Signer('X', 'x@example.sa', $method)));
                self::fail('Expected an exception for ' . $method->value);
            } catch (EsignException $e) {
                self::assertStringContainsString("\"{$method->value}\"", $e->getMessage());
            }
        }
        self::assertSame([], $this->docusign->calls);
    }

    // ---- details -------------------------------------------------------------------

    #[Test]
    public function details_report_each_signer_from_the_recipients(): void
    {
        $provider = $this->docusign->provider();
        $sent = $provider->send($this->envelope(Signer::email('Ahmed', 'ahmed@example.sa', 1), Signer::email('Sara', 'sara@example.sa', 2), Signer::email('Omar', 'omar@example.sa', 2)));
        $this->docusign->sign($sent->envelopeId, 'ahmed@example.sa');

        $details = $provider->details($sent->envelopeId);
        self::assertSame('docusign:' . $sent->envelopeId, (string) $details->ref);
        self::assertSame((string) $sent->ref(), (string) $details->ref);
        self::assertSame(EnvelopeStatus::PartiallySigned, $details->status);
        self::assertSame(['ahmed@example.sa', 'sara@example.sa', 'omar@example.sa'], array_map(fn ($s) => $s->email, $details->signers));
        self::assertSame([SignerStatus::Signed, SignerStatus::Pending, SignerStatus::Pending], array_map(fn ($s) => $s->status, $details->signers));
        self::assertSame([1, 2, 2], array_map(fn ($s) => $s->order, $details->signers));
        self::assertSame('Sara', $details->signers[1]->name);
        self::assertNull($details->signers[1]->signingUrl);
        self::assertCount(2, $details->pending());

        $this->docusign->decline($sent->envelopeId, 'omar@example.sa');
        $details = $provider->details($sent->envelopeId);
        self::assertSame(EnvelopeStatus::Declined, $details->status);
        self::assertSame(SignerStatus::Declined, $details->signers[2]->status);
    }

    // ---- reminders -------------------------------------------------------------------

    #[Test]
    public function remind_resends_to_that_recipient_only_without_correcting_the_address(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        $provider->remind($id, 'SARA@example.sa');

        self::assertSame([['envelopeId' => $id, 'recipientId' => '2']], $this->docusign->resent);
        self::assertSame(['signers' => [['recipientId' => '2']]], $this->docusign->lastBody('PUT', self::ENVELOPES . "/{$id}/recipients"));
        self::assertStringEndsWith('/recipients?resend_envelope=true', $this->docusign->calls[array_key_last($this->docusign->calls)]['url']);
    }

    #[Test]
    public function remind_refuses_a_signer_who_already_signed_or_is_not_on_the_envelope(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->sign($id, 'ahmed@example.sa');

        foreach (['ahmed@example.sa' => 'already acted', 'nobody@example.sa' => 'is not a signer'] as $email => $expected) {
            try {
                $provider->remind($id, $email);
                self::fail('Expected an exception');
            } catch (EsignException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
        self::assertSame([], $this->docusign->resent);
    }

    #[Test]
    public function a_recipient_error_inside_a_200_is_not_swallowed(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->docusign->failRecipientUpdates = true;     // e.g. the signer signed between our read and the resend

        try {
            $provider->remind($id, 'sara@example.sa');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('RECIPIENT_UPDATE_FAILED', $e->providerCode);
            self::assertSame(200, $e->httpStatus);
        }
        self::assertSame([], $this->docusign->resent);
    }

    // ---- expiry --------------------------------------------------------------------

    #[Test]
    public function extend_sets_expire_after_in_days_from_the_first_send_and_keeps_reminders(): void
    {
        $provider = $this->docusign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $sentAt = new \DateTimeImmutable('2026-10-18T10:00:00Z');
        $until = max($sentAt, new \DateTimeImmutable())->modify('+200 days 3 hours');
        $expectedDays = (int) ceil(($until->getTimestamp() - $sentAt->getTimestamp()) / 86400);

        $provider->extend($id, $until);

        self::assertSame([
            'useAccountDefaults' => 'false',
            'expirations' => ['expireEnabled' => 'true', 'expireAfter' => (string) $expectedDays, 'expireWarn' => '5'],
            'reminders' => ['reminderEnabled' => 'true', 'reminderDelay' => '2', 'reminderFrequency' => '3'],
        ], $this->docusign->notificationOf($id));
        self::assertGreaterThanOrEqual($until->getTimestamp(), $sentAt->getTimestamp() + $expectedDays * 86400);
    }

    #[Test]
    public function extend_refuses_a_past_deadline_before_any_http_call(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('in the future');
        try {
            $this->docusign->provider()->extend('env-1', new \DateTimeImmutable('-1 day'));
        } finally {
            self::assertSame([], $this->docusign->calls);
        }
    }

    // ---- webhooks ------------------------------------------------------------------

    #[Test]
    public function a_signed_connect_message_says_which_envelope_changed(): void
    {
        $body = self::connectMessage();
        $event = $this->docusign->provider()->parseWebhook(self::signed($body), $body);

        self::assertSame('docusign', $event->provider);
        self::assertSame('env-9', $event->envelopeId);
        self::assertNull($event->eventId);
        self::assertSame('envelope-completed', $event->payload['event']);
        self::assertSame([], $this->docusign->calls);         // never trusts or fetches anything
    }

    #[Test]
    public function any_signature_header_matching_any_configured_key_is_enough(): void
    {
        $body = self::connectMessage();
        $provider = $this->docusign->provider(new DocuSignOptions(['old-key', 'new-key']));
        $headers = [
            'x-docusign-signature-1' => [base64_encode(hash_hmac('sha256', $body, 'unrelated', true))],
            'x-docusign-signature-2' => [base64_encode(hash_hmac('sha256', $body, 'new-key', true))],
        ];

        self::assertSame('env-9', $provider->parseWebhook($headers, $body)->envelopeId);
    }

    #[Test]
    public function a_tampered_body_is_refused(): void
    {
        $body = self::connectMessage();
        $headers = self::signed($body);

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('does not match');
        $this->docusign->provider()->parseWebhook($headers, str_replace('env-9', 'env-8', $body));
    }

    #[Test]
    public function a_reformatted_body_is_refused_because_the_hmac_covers_raw_bytes(): void
    {
        $body = self::connectMessage();
        $headers = self::signed($body);

        $this->expectException(InvalidWebhook::class);
        $this->docusign->provider()->parseWebhook($headers, (string) json_encode(json_decode($body, true)));
    }

    #[Test]
    public function a_message_without_a_signature_header_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('X-DocuSign-Signature');
        $this->docusign->provider()->parseWebhook(['Content-Type' => 'application/json'], self::connectMessage());
    }

    #[Test]
    public function without_a_configured_secret_every_webhook_is_refused(): void
    {
        $body = self::connectMessage();

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('No DocuSign webhook_secret is configured');
        $this->docusign->provider(new DocuSignOptions())->parseWebhook(self::signed($body, ''), $body);
    }

    #[Test]
    public function a_signed_message_without_an_envelope_id_is_refused(): void
    {
        $body = '{"event":"template-created","data":{"templateId":"t-1"}}';

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('data.envelopeId');
        $this->docusign->provider()->parseWebhook(self::signed($body), $body);
    }

    #[Test]
    public function a_signed_xml_message_is_refused_with_a_hint(): void
    {
        $body = '<?xml version="1.0"?><DocuSignEnvelopeInformation/>';

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('not JSON');
        $this->docusign->provider()->parseWebhook(self::signed($body), $body);
    }
}
