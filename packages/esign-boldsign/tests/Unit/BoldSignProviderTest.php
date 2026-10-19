<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign\Tests\Unit;

use DateTimeImmutable;
use HamadaEmamTech\Esign\BoldSign\BoldSignOptions;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\BoldSign\Tests\Support\FakeBoldSign;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoldSignProviderTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private FakeBoldSign $boldsign;

    protected function setUp(): void
    {
        $this->boldsign = new FakeBoldSign();
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope(
            [new Document('contract.pdf', FakeBoldSign::ORIGINAL)],
            $signers === [] ? [
                Signer::email('Ahmed Ali', 'ahmed@example.com', 1, [new SignatureField(1, 60.4, 700, 150, 50)]),
                new Signer('Sara', 'sara@example.com', IdentityMethod::SmsOtp, mobile: '+966 50 000 0000', order: 2),
            ] : array_values($signers),
            subject: 'Please sign',
            message: 'Read section 4 first.',
            metadata: ['reference' => 'contract-42'],
        );
    }

    private function clock(): \Closure
    {
        return static fn (): int => self::NOW;
    }

    // ---- send ----------------------------------------------------------------------

    #[Test]
    public function send_posts_one_json_request_and_returns_the_document_id(): void
    {
        $sent = $this->boldsign->provider()->send($this->envelope());

        self::assertSame('boldsign', $sent->provider);
        self::assertSame('8f59295d-0000-0000-0000-000000000001', $sent->envelopeId);
        self::assertSame(EnvelopeStatus::Sent, $sent->status);
        self::assertSame([], $sent->signingUrls);       // BoldSign emails the links
        self::assertSame(['/v1/document/send'], $this->boldsign->paths());
        self::assertSame(['application/json'], $this->boldsign->calls[0]['headers']['Content-Type']);

        $body = $this->boldsign->lastBody('/v1/document/send');
        self::assertSame('Please sign', $body['Title']);
        self::assertSame('Read section 4 first.', $body['Message']);
        self::assertSame(['reference' => 'contract-42'], $body['MetaData']);
        self::assertSame([['base64' => 'data:application/pdf;base64,' . base64_encode(FakeBoldSign::ORIGINAL), 'fileName' => 'contract.pdf']], $body['Files']);
        self::assertArrayNotHasKey('ExpiryDays', $body);   // BoldSign's own default unless set
    }

    #[Test]
    public function signers_are_mapped_with_order_authentication_and_bounds(): void
    {
        $this->boldsign->provider()->send($this->envelope());
        $body = $this->boldsign->lastBody('/v1/document/send');
        [$ahmed, $sara] = $body['Signers'];

        self::assertTrue($body['EnableSigningOrder']);
        self::assertSame(['Name' => 'Ahmed Ali', 'EmailAddress' => 'ahmed@example.com', 'SignerType' => 'Signer', 'SignerOrder' => 1], array_diff_key($ahmed, ['FormFields' => true]));
        self::assertArrayNotHasKey('AuthenticationType', $ahmed);     // email link only: BoldSign's "None"
        self::assertSame(
            [['FieldType' => 'Signature', 'PageNumber' => 1, 'Bounds' => ['X' => 60.4, 'Y' => 700, 'Width' => 150, 'Height' => 50], 'IsRequired' => true]],
            $ahmed['FormFields'],
        );

        self::assertSame(2, $sara['SignerOrder']);
        self::assertSame('SMSOTP', $sara['AuthenticationType']);
        self::assertSame(['CountryCode' => '+966', 'Number' => '500000000'], $sara['PhoneNumber']);
        self::assertArrayNotHasKey('FormFields', $sara);
    }

    #[Test]
    public function signers_with_the_same_order_sign_in_parallel(): void
    {
        $this->boldsign->provider()->send($this->envelope(Signer::email('A', 'a@example.com'), Signer::email('B', 'b@example.com')));
        $body = $this->boldsign->lastBody('/v1/document/send');

        self::assertFalse($body['EnableSigningOrder']);
        self::assertArrayNotHasKey('SignerOrder', $body['Signers'][0]);
        self::assertArrayNotHasKey('SignerOrder', $body['Signers'][1]);
    }

    #[Test]
    public function options_add_email_otp_expiry_and_scale_the_bounds(): void
    {
        $options = new BoldSignOptions(webhookSecret: FakeBoldSign::SECRET, emailOtp: true, expiryDays: 14, coordinateScale: 0.5);
        $this->boldsign->provider($options)->send($this->envelope(Signer::email('A', 'a@example.com', 1, [new SignatureField(2, 100, 200, 150, 50)])));
        $body = $this->boldsign->lastBody('/v1/document/send');

        self::assertSame(14, $body['ExpiryDays']);
        self::assertSame('EmailOTP', $body['Signers'][0]['AuthenticationType']);
        self::assertSame(['X' => 50, 'Y' => 100, 'Width' => 75, 'Height' => 25], $body['Signers'][0]['FormFields'][0]['Bounds']);
        self::assertSame(2, $body['Signers'][0]['FormFields'][0]['PageNumber']);
    }

    #[Test]
    public function an_unsupported_identity_method_is_refused_before_calling_boldsign(): void
    {
        try {
            $this->boldsign->provider()->send($this->envelope(Signer::nafath('1012345678', 'Omar', 'omar@example.com')));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('"nafath"', $e->getMessage());
        }
        self::assertSame([], $this->boldsign->calls);
    }

    #[Test]
    public function sms_otp_without_a_separated_country_code_is_refused_before_calling_boldsign(): void
    {
        foreach ([null, '+966500000000', '0500000000'] as $mobile) {
            try {
                $this->boldsign->provider()->send($this->envelope(new Signer('Omar', 'omar@example.com', IdentityMethod::SmsOtp, mobile: $mobile)));
                self::fail('Expected an exception for ' . var_export($mobile, true));
            } catch (EsignException $e) {
                self::assertStringContainsString('+966 500000000', $e->getMessage());
            }
        }
        self::assertSame([], $this->boldsign->calls);
    }

    #[Test]
    public function documents_and_fields_boldsign_cannot_take_are_refused_before_calling_it(): void
    {
        $cases = [
            'file type' => new Envelope([new Document('notes.txt', 'hello', 'text/plain')], [Signer::email('A', 'a@example.com')]),
            'second document' => new Envelope(
                [new Document('a.pdf', '%PDF a'), new Document('b.pdf', '%PDF b')],
                [Signer::email('A', 'a@example.com', 1, [new SignatureField(1, 10, 10, documentIndex: 1)])],
            ),
            'zero size' => $this->envelope(Signer::email('A', 'a@example.com', 1, [new SignatureField(1, 10, 10, 0, 50)])),
            'metadata' => new Envelope([new Document('a.pdf', '%PDF a')], [Signer::email('A', 'a@example.com')], metadata: ['k' => str_repeat('x', 501)]),
        ];
        foreach ($cases as $case => $envelope) {
            try {
                $this->boldsign->provider()->send($envelope);
                self::fail("Expected an exception for {$case}");
            } catch (EsignException) {
            }
        }
        self::assertSame([], $this->boldsign->calls);
    }

    #[Test]
    public function several_documents_are_sent_together_when_fields_are_on_the_first(): void
    {
        $envelope = new Envelope(
            [new Document('a.pdf', '%PDF a'), new Document('b.png', 'png-bytes', 'image/png')],
            [Signer::email('A', 'a@example.com', 1, [new SignatureField(1, 10, 10)])],
        );
        $this->boldsign->provider()->send($envelope);

        $files = $this->boldsign->lastBody('/v1/document/send')['Files'];
        self::assertSame(['a.pdf', 'b.png'], array_column($files, 'fileName'));
        self::assertStringStartsWith('data:image/png;base64,', $files[1]['base64']);
    }

    // ---- status --------------------------------------------------------------------

    #[Test]
    public function status_follows_the_document_and_its_signers(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        self::assertSame(['documentId' => $id], $this->boldsign->calls[1]['query']);

        $this->boldsign->sign($id, 'ahmed@example.com');
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));

        $this->boldsign->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function every_final_boldsign_status_is_mapped(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        foreach (['Declined' => EnvelopeStatus::Declined, 'Revoked' => EnvelopeStatus::Cancelled, 'Expired' => EnvelopeStatus::Expired] as $raw => $expected) {
            $this->boldsign->setStatus($id, $raw);
            self::assertSame($expected, $provider->status($id), $raw);
        }
    }

    #[Test]
    public function completed_while_a_signer_still_shows_not_completed_is_not_signed(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->sign($id, 'ahmed@example.com');
        $this->boldsign->forcedStatus = 'Completed';

        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));
        $this->expectException(EsignException::class);
        $provider->download($id);
    }

    #[Test]
    public function an_unknown_boldsign_status_fails_loudly_instead_of_guessing(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->forcedStatus = 'Draft';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('"Draft"');
        $provider->status($id);
    }

    #[Test]
    public function an_unknown_document_is_a_404_provider_error(): void
    {
        try {
            $this->boldsign->provider()->status('does-not-exist');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(404, $e->httpStatus);
            self::assertStringContainsString('Document not found.', $e->getMessage());
        }
    }

    // ---- download ------------------------------------------------------------------

    #[Test]
    public function download_returns_the_signed_pdf_once_completed(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->signAll($id);

        self::assertSame(FakeBoldSign::SIGNED, $provider->download($id));
        self::assertSame(['documentId' => $id], $this->boldsign->calls[array_key_last($this->boldsign->calls)]['query']);
    }

    #[Test]
    public function download_refuses_before_everyone_signed_without_fetching_the_file(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->sign($id, 'ahmed@example.com');

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('partially_signed', $e->getMessage());
        }
        self::assertNotContains('/v1/document/download', $this->boldsign->paths());
    }

    // ---- cancel --------------------------------------------------------------------

    #[Test]
    public function cancel_revokes_with_a_reason(): void
    {
        $provider = $this->boldsign->provider(new BoldSignOptions(revokeMessage: 'Contract withdrawn.'));
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        self::assertSame(['Message' => 'Contract withdrawn.'], $this->boldsign->lastBody('/v1/document/revoke'));
        self::assertSame(EnvelopeStatus::Cancelled, $provider->status($id));
    }

    #[Test]
    public function revoking_a_completed_document_surfaces_boldsigns_error(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->signAll($id);

        try {
            $provider->cancel($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('cannot be revoked', $e->getMessage());
        }
    }

    // ---- errors --------------------------------------------------------------------

    #[Test]
    public function a_wrong_api_key_is_a_401_that_never_contains_the_key(): void
    {
        $key = 'wrong-key-that-must-not-leak';

        try {
            $this->boldsign->provider(apiKey: $key)->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('boldsign', $e->provider);
            self::assertStringContainsString('region', $e->getMessage());
            self::assertStringNotContainsString($key, $e->getMessage());
        }
    }

    #[Test]
    public function an_error_body_that_echoes_the_key_is_scrubbed(): void
    {
        $this->boldsign->failWith = 422;
        $this->boldsign->failBody = (string) json_encode(['title' => 'Invalid', 'errors' => ['Signers[0].EmailAddress' => ['Bad email for key ' . FakeBoldSign::API_KEY]]]);

        try {
            $this->boldsign->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('Signers[0].EmailAddress: Bad email', $e->getMessage());
            self::assertStringNotContainsString(FakeBoldSign::API_KEY, $e->getMessage());
        }
    }

    #[Test]
    public function a_non_json_error_body_is_not_echoed(): void
    {
        $this->boldsign->failWith = 500;
        $this->boldsign->failBody = '<html>stack trace</html>';

        try {
            $this->boldsign->provider()->status('x');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(500, $e->httpStatus);
            self::assertStringContainsString('unavailable', $e->getMessage());
            self::assertStringNotContainsString('stack trace', $e->getMessage());
        }
    }

    #[Test]
    public function a_rate_limit_is_explained(): void
    {
        $this->boldsign->failWith = 429;

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('Rate limit');
        $this->boldsign->provider()->status('x');
    }

    // ---- webhooks ------------------------------------------------------------------

    #[Test]
    public function a_correctly_signed_webhook_says_which_document_changed(): void
    {
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW - 60);
        $event = $this->boldsign->provider(clock: $this->clock())->parseWebhook($headers, $body);

        self::assertSame('boldsign', $event->provider);
        self::assertSame('doc-9', $event->envelopeId);
        self::assertSame('evt-Completed', $event->eventId);
        self::assertSame('Completed', $event->payload['event']['eventType']);
        self::assertSame([], $this->boldsign->calls);      // never calls BoldSign
    }

    #[Test]
    public function header_names_are_case_insensitive_and_list_values_work(): void
    {
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Signed', self::NOW);
        $event = $this->boldsign->provider(clock: $this->clock())->parseWebhook(['X-BoldSign-Signature' => [$headers['x-boldsign-signature']]], $body);

        self::assertSame('doc-9', $event->envelopeId);
    }

    #[Test]
    public function the_previous_secret_signature_s1_is_accepted_during_a_rollover(): void
    {
        [, $body] = FakeBoldSign::webhook('doc-9', 'Signed', self::NOW);
        $new = hash_hmac('sha256', self::NOW . '.' . $body, 'brand-new-secret');
        $old = hash_hmac('sha256', self::NOW . '.' . $body, FakeBoldSign::SECRET);

        $event = $this->boldsign->provider(clock: $this->clock())->parseWebhook(['x-boldsign-signature' => 't=' . self::NOW . ", s0={$new}, s1={$old}"], $body);
        self::assertSame('doc-9', $event->envelopeId);
    }

    #[Test]
    public function a_tampered_body_is_refused(): void
    {
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW);

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('does not match');
        $this->boldsign->provider(clock: $this->clock())->parseWebhook($headers, str_replace('doc-9', 'doc-1', $body));
    }

    #[Test]
    public function a_tampered_timestamp_is_refused(): void
    {
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW);
        $headers['x-boldsign-signature'] = str_replace('t=' . self::NOW, 't=' . (self::NOW + 1), $headers['x-boldsign-signature']);

        $this->expectException(InvalidWebhook::class);
        $this->boldsign->provider(clock: $this->clock())->parseWebhook($headers, $body);
    }

    #[Test]
    public function a_webhook_signed_with_another_secret_is_refused(): void
    {
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW, 'someone-elses-secret');

        $this->expectException(InvalidWebhook::class);
        $this->boldsign->provider(clock: $this->clock())->parseWebhook($headers, $body);
    }

    #[Test]
    public function a_stale_or_future_timestamp_is_refused_even_when_correctly_signed(): void
    {
        $provider = $this->boldsign->provider(clock: $this->clock());

        foreach ([self::NOW - 301, self::NOW + 301] as $timestamp) {
            [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', $timestamp);
            try {
                $provider->parseWebhook($headers, $body);
                self::fail("Expected an exception for t={$timestamp}");
            } catch (InvalidWebhook $e) {
                self::assertStringContainsString('300 seconds', $e->getMessage());
            }
        }
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW - 300);
        self::assertSame('doc-9', $provider->parseWebhook($headers, $body)->envelopeId);   // the edge is inside
    }

    #[Test]
    public function the_timestamp_tolerance_is_configurable(): void
    {
        $provider = $this->boldsign->provider(new BoldSignOptions(webhookSecret: FakeBoldSign::SECRET, webhookTolerance: 3600), $this->clock());
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW - 1800);

        self::assertSame('doc-9', $provider->parseWebhook($headers, $body)->envelopeId);
    }

    #[Test]
    public function without_a_secret_every_webhook_is_refused(): void
    {
        [$headers, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW);

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('webhook_secret is not configured');
        $this->boldsign->provider(new BoldSignOptions(), $this->clock())->parseWebhook($headers, $body);
    }

    #[Test]
    public function missing_or_malformed_signature_headers_are_refused(): void
    {
        [, $body] = FakeBoldSign::webhook('doc-9', 'Completed', self::NOW);
        $provider = $this->boldsign->provider(clock: $this->clock());

        foreach ([[], ['x-boldsign-signature' => ''], ['x-boldsign-signature' => 's0=abc'], ['x-boldsign-signature' => 't=abc, s0=def'], ['x-boldsign-signature' => 't=' . self::NOW]] as $headers) {
            try {
                $provider->parseWebhook($headers, $body);
                self::fail('Expected an exception for ' . json_encode($headers));
            } catch (InvalidWebhook) {
            }
        }
        self::assertSame(0, count($this->boldsign->calls));
    }

    #[Test]
    public function the_verification_ping_is_answered_without_a_document(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('verification request');
        $this->boldsign->provider(clock: $this->clock())->parseWebhook(['X-BoldSign-Event' => 'Verification'], '{}');
    }

    #[Test]
    public function a_signed_event_that_is_not_about_a_document_is_refused(): void
    {
        $body = '{"event":{"id":"e1","eventType":"SenderIdentityCreated"},"data":{"object":"senderIdentity"}}';
        $signature = hash_hmac('sha256', self::NOW . '.' . $body, FakeBoldSign::SECRET);

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('data.documentId');
        $this->boldsign->provider(clock: $this->clock())->parseWebhook(['x-boldsign-signature' => 't=' . self::NOW . ", s0={$signature}"], $body);
    }

    // ---- BoldSign-only ---------------------------------------------------------------

    #[Test]
    public function signing_link_asks_for_an_embedded_link_for_one_signer(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        $link = $provider->signingLink($id, 'ahmed@example.com', 'https://app.example.com/done');

        self::assertStringStartsWith('https://app.boldsign.test/document/sign/', $link);
        self::assertSame(
            ['documentId' => $id, 'signerEmail' => 'ahmed@example.com', 'redirectUrl' => 'https://app.example.com/done'],
            $this->boldsign->calls[array_key_last($this->boldsign->calls)]['query'],
        );
    }

    // ---- capabilities and optional features --------------------------------------------

    #[Test]
    public function capabilities_declare_what_boldsign_documents(): void
    {
        $capabilities = $this->boldsign->provider()->capabilities();

        self::assertSame([IdentityMethod::Email, IdentityMethod::SmsOtp], $capabilities->identityMethods);
        self::assertSame(25, $capabilities->maxDocuments);
        self::assertFalse($capabilities->asyncCancel);
        foreach ([Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement, Feature::Reminders, Feature::ExpiryExtension, Feature::SignerDetails] as $feature) {
            self::assertTrue($capabilities->supports($feature), $feature->value);
        }
        self::assertFalse($capabilities->supports(Feature::SigningUrls));         // send() returns no links
        self::assertFalse($capabilities->supports(Feature::WebhookManagement));   // set up in BoldSign's dashboard
    }

    #[Test]
    public function details_report_each_signer_from_signer_details(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->sign($id, 'ahmed@example.com');

        $details = $provider->details($id);

        self::assertSame('boldsign:' . $id, (string) $details->ref);
        self::assertSame(EnvelopeStatus::PartiallySigned, $details->status);
        self::assertCount(2, $details->signers);
        [$ahmed, $sara] = $details->signers;
        self::assertSame(['ahmed@example.com', 'Ahmed Ali', SignerStatus::Signed, null, 1], [$ahmed->email, $ahmed->name, $ahmed->status, $ahmed->signingUrl, $ahmed->order]);
        self::assertSame(['sara@example.com', 'Sara', SignerStatus::Pending, 2], [$sara->email, $sara->name, $sara->status, $sara->order]);
        self::assertSame([$sara], $details->pending());

        $this->boldsign->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->details($id)->status);
        self::assertSame([], $provider->details($id)->pending());
    }

    #[Test]
    public function remind_posts_to_the_remind_endpoint_for_one_signer(): void
    {
        $provider = $this->boldsign->provider(new BoldSignOptions(reminderMessage: 'Still waiting on you.'));
        $id = $provider->send($this->envelope())->envelopeId;

        $provider->remind($id, 'sara@example.com');

        $call = $this->boldsign->calls[array_key_last($this->boldsign->calls)];
        self::assertSame(['POST', '/v1/document/remind'], [$call['method'], $call['path']]);
        self::assertSame(['documentId' => $id, 'receiverEmails' => 'sara@example.com'], $call['query']);
        self::assertSame(['Message' => 'Still waiting on you.'], $call['body']);
        self::assertSame(1, count(array_keys($this->boldsign->paths(), '/v1/document/send', true)));   // nothing re-sent
    }

    #[Test]
    public function remind_for_an_unknown_document_is_a_provider_error(): void
    {
        $this->expectException(ProviderError::class);
        $this->boldsign->provider()->remind('nope', 'sara@example.com');
    }

    #[Test]
    public function extend_patches_the_new_expiry_date(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        $provider->extend($id, new DateTimeImmutable('2026-12-15 10:30:00'));

        $call = $this->boldsign->calls[array_key_last($this->boldsign->calls)];
        self::assertSame(['PATCH', '/v1/document/extendExpiry', ['documentId' => $id]], [$call['method'], $call['path'], $call['query']]);
        self::assertSame(['NewExpiryValue' => '2026-12-15'], $call['body']);
        self::assertSame('2026-12-15', $this->boldsign->expiries[$id]);
    }

    #[Test]
    public function extending_a_signed_document_surfaces_boldsigns_error(): void
    {
        $provider = $this->boldsign->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->boldsign->signAll($id);

        try {
            $provider->extend($id, new DateTimeImmutable('+10 days'));
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(400, $e->httpStatus);
        }
    }
}
