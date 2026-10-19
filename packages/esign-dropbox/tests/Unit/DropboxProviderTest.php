<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox\Tests\Unit;

use HamadaEmamTech\Esign\Dropbox\DropboxOptions;
use HamadaEmamTech\Esign\Dropbox\DropboxProvider;
use HamadaEmamTech\Esign\Dropbox\Tests\Support\FakeDropbox;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\EnvelopeNotSupported;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropboxProviderTest extends TestCase
{
    private const SEND = '/v3/signature_request/send';

    private FakeDropbox $dropbox;

    protected function setUp(): void
    {
        $this->dropbox = new FakeDropbox();
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope(
            [new Document('contract.pdf', "%PDF-1.4 original\n"), new Document('annex.pdf', "%PDF-1.4 annex\n")],
            $signers === [] ? [
                Signer::email('Ahmed Ali', 'ahmed@example.com', 1, [new SignatureField(1, 60.4, 700, 150, 50), new SignatureField(2, 72, 100, 90, 36, documentIndex: 1)]),
                Signer::email('Sara', 'sara@example.com', 2, [new SignatureField(3, 300, 650.6)]),
            ] : array_values($signers),
            subject: 'Please sign',
            message: 'Thanks!',
            metadata: ['reference' => 'contract-42'],
        );
    }

    // ---- send ----------------------------------------------------------------------

    #[Test]
    public function send_uploads_the_files_and_returns_the_signature_request_id(): void
    {
        $sent = $this->dropbox->provider()->send($this->envelope());

        self::assertSame('dropbox', $sent->provider);
        self::assertSame(sprintf('%040x', 1), $sent->envelopeId);
        self::assertSame(EnvelopeStatus::Sent, $sent->status);
        self::assertSame([], $sent->signingUrls);     // signers get Dropbox Sign's email

        $files = array_values(array_filter($this->dropbox->lastParts(self::SEND), fn (array $p) => $p['filename'] !== null));
        self::assertSame(['files[0]', 'files[1]'], array_column($files, 'name'));
        self::assertSame(['contract.pdf', 'annex.pdf'], array_column($files, 'filename'));
        self::assertSame(['application/pdf', 'application/pdf'], array_column($files, 'contentType'));
        self::assertSame("%PDF-1.4 original\n", $files[0]['contents']);

        $form = $this->dropbox->lastForm(self::SEND);
        self::assertSame('Please sign', $form['title']);
        self::assertSame('Please sign', $form['subject']);
        self::assertSame('Thanks!', $form['message']);
        self::assertSame('contract-42', $form['metadata[reference]']);
        self::assertArrayNotHasKey('client_id', $form);
    }

    #[Test]
    public function signers_are_sent_in_envelope_order_with_a_zero_based_signing_order(): void
    {
        $this->dropbox->provider()->send($this->envelope(
            Signer::email('Third', 'c@example.com', 7),
            Signer::email('First', 'a@example.com', 2),
            Signer::email('Second', 'b@example.com', 5),
        ));
        $form = $this->dropbox->lastForm(self::SEND);

        self::assertSame('Third', $form['signers[0][name]']);
        self::assertSame('c@example.com', $form['signers[0][email_address]']);
        self::assertSame(['2', '0', '1'], [$form['signers[0][order]'], $form['signers[1][order]'], $form['signers[2][order]']]);
    }

    #[Test]
    public function signers_with_the_same_order_sign_in_parallel_so_no_order_is_sent(): void
    {
        $this->dropbox->provider()->send($this->envelope(Signer::email('A', 'a@example.com'), Signer::email('B', 'b@example.com')));

        self::assertSame([], preg_grep('/\[order\]$/', array_keys($this->dropbox->lastForm(self::SEND))));
    }

    #[Test]
    public function mixed_parallel_and_sequential_orders_are_refused_before_calling_dropbox(): void
    {
        try {
            $this->dropbox->provider()->send($this->envelope(Signer::email('A', 'a@example.com', 1), Signer::email('B', 'b@example.com', 1), Signer::email('C', 'c@example.com', 2)));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('Mixed orders', $e->getMessage());
        }
        self::assertSame([], $this->dropbox->calls);
    }

    #[Test]
    public function signature_fields_become_form_fields_per_document(): void
    {
        $this->dropbox->provider()->send($this->envelope());
        $fields = json_decode($this->dropbox->lastForm(self::SEND)['form_fields_per_document'], true);

        // x/y: PDF points from the top-left = the 72 DPI system that "page" selects.
        // width/height: always 80 DPI, so * 80/72, plus 2 on the width (Dropbox Sign's FAQ).
        self::assertSame([
            ['document_index' => 0, 'api_id' => 'esign_signature_0_0', 'type' => 'signature', 'required' => true, 'signer' => 0, 'page' => 1, 'x' => 60, 'y' => 700, 'width' => 169, 'height' => 56],
            ['document_index' => 1, 'api_id' => 'esign_signature_0_1', 'type' => 'signature', 'required' => true, 'signer' => 0, 'page' => 2, 'x' => 72, 'y' => 100, 'width' => 102, 'height' => 40],
            ['document_index' => 0, 'api_id' => 'esign_signature_1_0', 'type' => 'signature', 'required' => true, 'signer' => 1, 'page' => 3, 'x' => 300, 'y' => 651, 'width' => 169, 'height' => 56],
        ], $fields);
    }

    #[Test]
    public function without_any_fields_dropbox_sign_adds_its_signature_page(): void
    {
        $this->dropbox->provider()->send($this->envelope(Signer::email('A', 'a@example.com'), Signer::email('B', 'b@example.com')));

        self::assertArrayNotHasKey('form_fields_per_document', $this->dropbox->lastForm(self::SEND));
    }

    #[Test]
    public function fields_for_some_signers_but_not_others_are_refused(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('every signer a signature field, or none');
        $this->dropbox->provider()->send($this->envelope(
            Signer::email('A', 'a@example.com', 1, [new SignatureField(1, 10, 10)]),
            Signer::email('B', 'b@example.com', 2),
        ));
    }

    #[Test]
    public function a_field_on_a_missing_document_is_refused_before_calling_dropbox(): void
    {
        try {
            $this->dropbox->provider()->send($this->envelope(Signer::email('A', 'a@example.com', 1, [new SignatureField(1, 10, 10, documentIndex: 5)])));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('document 5', $e->getMessage());
        }
        self::assertSame([], $this->dropbox->calls);
    }

    #[Test]
    public function test_mode_is_on_by_default_and_can_be_turned_off(): void
    {
        $this->dropbox->provider()->send($this->envelope());
        self::assertSame('1', $this->dropbox->lastForm(self::SEND)['test_mode']);

        $this->dropbox->provider(new DropboxOptions(testMode: false, clientId: 'app-123'))->send($this->envelope());
        self::assertSame('0', $this->dropbox->lastForm(self::SEND)['test_mode']);
        self::assertSame('app-123', $this->dropbox->lastForm(self::SEND)['client_id']);
    }

    #[Test]
    public function too_much_metadata_is_refused_before_calling_dropbox(): void
    {
        $metadata = [];
        for ($i = 0; $i < 11; ++$i) {
            $metadata["k{$i}"] = 'v';
        }

        try {
            $this->dropbox->provider()->send(new Envelope([new Document('a.pdf', '%PDF a')], [Signer::email('A', 'a@example.com')], metadata: $metadata));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('at most 10 metadata keys', $e->getMessage());
        }
        self::assertSame([], $this->dropbox->calls);
    }

    // ---- identity --------------------------------------------------------------------

    #[Test]
    public function nafath_is_refused_before_calling_dropbox(): void
    {
        try {
            $this->dropbox->provider()->send($this->envelope(Signer::nafath('1012345678', 'Omar', 'omar@example.com')));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('"nafath"', $e->getMessage());
        }
        self::assertSame([], $this->dropbox->calls);
    }

    #[Test]
    public function sms_otp_sends_the_number_for_sms_authentication_outside_test_mode(): void
    {
        $sms = new Signer('Omar', 'omar@example.com', IdentityMethod::SmsOtp, mobile: '+966500000000');
        $this->dropbox->provider(new DropboxOptions(testMode: false))->send($this->envelope($sms));
        $form = $this->dropbox->lastForm(self::SEND);

        self::assertSame('+966500000000', $form['signers[0][sms_phone_number]']);
        self::assertSame('authentication', $form['signers[0][sms_phone_number_type]']);
    }

    #[Test]
    public function sms_otp_is_refused_in_test_mode_and_without_an_e164_number(): void
    {
        $sms = new Signer('Omar', 'omar@example.com', IdentityMethod::SmsOtp, mobile: '+966500000000');
        try {
            $this->dropbox->provider()->send($this->envelope($sms));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('test mode', $e->getMessage());
        }

        $local = new Signer('Omar', 'omar@example.com', IdentityMethod::SmsOtp, mobile: '0500000000');
        try {
            $this->dropbox->provider(new DropboxOptions(testMode: false))->send($this->envelope($local));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('E.164', $e->getMessage());
        }
        self::assertSame([], $this->dropbox->calls);
    }

    // ---- status --------------------------------------------------------------------

    #[Test]
    public function status_follows_the_signers(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));

        $this->dropbox->sign($id, 'ahmed@example.com');
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));

        $this->dropbox->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function a_decline_by_any_signer_declines_the_envelope(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->sign($id, 'ahmed@example.com');
        $this->dropbox->decline($id, 'sara@example.com');

        self::assertSame(EnvelopeStatus::Declined, $provider->status($id));
    }

    #[Test]
    public function a_cancelled_request_answers_410_and_maps_to_cancelled(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        self::assertContains("/v3/signature_request/cancel/{$id}", $this->dropbox->paths());
        self::assertSame(EnvelopeStatus::Cancelled, $provider->status($id));
    }

    #[Test]
    public function an_expired_request_maps_to_expired(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->expire($id);

        self::assertSame(EnvelopeStatus::Expired, $provider->status($id));

        $this->dropbox->forcedStatusCode = 'expired';
        self::assertSame(EnvelopeStatus::Expired, $provider->status($id));
    }

    #[Test]
    public function has_error_maps_to_failed_instead_of_looking_like_sent(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->breakRequest($id);

        self::assertSame(EnvelopeStatus::Failed, $provider->status($id));
        self::assertTrue(EnvelopeStatus::Failed->isFinal());
    }

    #[Test]
    public function an_unknown_status_code_fails_loudly_instead_of_guessing(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->forcedStatusCode = 'archived';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('"archived"');
        $provider->status($id);
    }

    #[Test]
    public function an_unknown_request_is_a_404_provider_error(): void
    {
        try {
            $this->dropbox->provider()->status('does-not-exist');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(404, $e->httpStatus);
            self::assertSame('not_found', $e->providerCode);
        }
    }

    // ---- details ---------------------------------------------------------------------

    #[Test]
    public function details_reports_each_signer_from_signatures(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope(
            Signer::email('Ahmed Ali', 'ahmed@example.com', 1),
            Signer::email('Sara', 'sara@example.com', 2),
            Signer::email('Omar', 'omar@example.com', 3),
        ))->envelopeId;
        $this->dropbox->sign($id, 'ahmed@example.com');
        $this->dropbox->decline($id, 'omar@example.com');

        $details = $provider->details($id);

        self::assertSame("dropbox:{$id}", (string) $details->ref);
        self::assertSame(EnvelopeStatus::Declined, $details->status);
        self::assertSame(['ahmed@example.com', 'sara@example.com', 'omar@example.com'], array_map(fn ($s) => $s->email, $details->signers));
        self::assertSame([SignerStatus::Signed, SignerStatus::Pending, SignerStatus::Declined], array_map(fn ($s) => $s->status, $details->signers));
        self::assertSame([0, 1, 2], array_map(fn ($s) => $s->order, $details->signers));      // Dropbox Sign's 0-based order
        self::assertSame('Sara', $details->pending()[0]->name);
        self::assertNull($details->signers[0]->signingUrl);
    }

    #[Test]
    public function details_of_a_cancelled_request_has_no_signers(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        $details = $provider->details($id);
        self::assertSame(EnvelopeStatus::Cancelled, $details->status);
        self::assertSame([], $details->signers);
    }

    // ---- remind ----------------------------------------------------------------------

    #[Test]
    public function remind_posts_the_signers_email_as_json(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        $provider->remind($id, 'sara@example.com');

        self::assertSame(['email_address' => 'sara@example.com'], $this->dropbox->lastJson("/v3/signature_request/remind/{$id}"));
    }

    #[Test]
    public function a_second_reminder_within_the_hour_surfaces_dropbox_signs_error(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->remind($id, 'sara@example.com');

        try {
            $provider->remind($id, 'sara@example.com');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('invalid_reminder', $e->providerCode);
        }
    }

    // ---- cancel / errors -------------------------------------------------------------

    #[Test]
    public function cancelling_a_completed_request_surfaces_dropbox_signs_error(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->signAll($id);

        try {
            $provider->cancel($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('signature_request_cancel_failed', $e->providerCode);
            self::assertStringStartsWith('[dropbox] POST /signature_request/cancel/', $e->getMessage());
        }
    }

    #[Test]
    public function a_wrong_api_key_is_a_401_that_never_contains_the_key(): void
    {
        try {
            $this->dropbox->provider(null, 'wrong-key-do-not-leak')->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('unauthorized', $e->providerCode);
            self::assertStringContainsString('Check api_key', $e->getMessage());
            self::assertStringNotContainsString('wrong-key-do-not-leak', $e->getMessage());
            self::assertStringNotContainsString(base64_encode('wrong-key-do-not-leak:'), $e->getMessage());
        }
    }

    #[Test]
    public function the_api_key_is_sent_as_basic_auth_username_with_an_empty_password(): void
    {
        $this->dropbox->provider()->send($this->envelope());

        // FakeDropbox only accepts exactly this header; reaching the send endpoint proves it.
        self::assertSame([self::SEND], $this->dropbox->paths());
    }

    // ---- download ------------------------------------------------------------------

    #[Test]
    public function download_returns_the_pdf_once_everyone_signed(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->signAll($id);

        self::assertSame(FakeDropbox::SIGNED, $provider->download($id));
        $last = end($this->dropbox->calls);
        self::assertNotFalse($last);
        self::assertSame('file_type=pdf', $last['query']);
    }

    #[Test]
    public function download_refuses_before_everyone_signed_without_fetching_files(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->sign($id, 'ahmed@example.com');

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('partially_signed', $e->getMessage());
        }
        self::assertNotContains("/v3/signature_request/files/{$id}", $this->dropbox->paths());
    }

    #[Test]
    public function files_still_being_prepared_say_so_with_a_409(): void
    {
        $provider = $this->dropbox->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->dropbox->signAll($id);
        $this->dropbox->filesPreparing = 1;

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertStringContainsString('still being prepared', $e->getMessage());
        }
        self::assertSame(FakeDropbox::SIGNED, $provider->download($id));
    }

    // ---- webhooks ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function event(string $type = 'signature_request_signed', ?string $key = FakeDropbox::API_KEY, string $id = 'abc123'): array
    {
        $time = '1348177752';

        return [
            'event' => [
                'event_time' => $time,
                'event_type' => $type,
                'event_hash' => hash_hmac('sha256', $time . $type, (string) $key),
                'event_metadata' => ['related_signature_id' => 'sig-0', 'reported_for_account_id' => 'acct', 'reported_for_app_id' => null],
            ],
            'signature_request' => ['signature_request_id' => $id, 'is_complete' => false],
        ];
    }

    #[Test]
    public function a_multipart_event_with_a_valid_hash_says_which_request_changed(): void
    {
        $json = (string) json_encode($this->event());
        $boundary = '------------------------a1b2c3';
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"json\"\r\n\r\n{$json}\r\n--{$boundary}--\r\n";

        $event = $this->dropbox->provider()->parseWebhook(['Content-Type' => "multipart/form-data; boundary={$boundary}"], $body);

        self::assertSame('dropbox', $event->provider);
        self::assertSame('abc123', $event->envelopeId);
        self::assertSame('signature_request_signed', $event->payload['event']['event_type']);
        // Without the header, the boundary is read from the body itself.
        self::assertSame('abc123', $this->dropbox->provider()->parseWebhook([], $body)->envelopeId);
    }

    #[Test]
    public function a_form_encoded_or_plain_json_event_is_accepted_too(): void
    {
        $json = (string) json_encode($this->event('signature_request_all_signed'));
        $provider = $this->dropbox->provider();

        self::assertSame('abc123', $provider->parseWebhook(['content-type' => 'application/x-www-form-urlencoded'], http_build_query(['json' => $json]))->envelopeId);
        self::assertSame('abc123', $provider->parseWebhook([], $json)->envelopeId);
    }

    #[Test]
    public function a_tampered_event_is_refused(): void
    {
        $event = $this->event('signature_request_signed');
        $event['event']['event_type'] = 'signature_request_all_signed';     // hash was for "signed"

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('event_hash does not match');
        $this->dropbox->provider()->parseWebhook([], (string) json_encode($event));
    }

    #[Test]
    public function an_event_signed_with_another_key_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->dropbox->provider()->parseWebhook([], (string) json_encode($this->event(key: 'someone-elses-key')));
    }

    #[Test]
    public function without_an_api_key_every_event_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('none is configured');
        $this->dropbox->provider(null, '')->parseWebhook([], (string) json_encode($this->event(key: '')));
    }

    #[Test]
    public function a_verified_event_without_a_signature_request_is_refused(): void
    {
        $event = $this->event('callback_test');
        unset($event['signature_request']);

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('"callback_test" is not about a signature request');
        $this->dropbox->provider()->parseWebhook([], (string) json_encode($event));
    }

    #[Test]
    public function garbage_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->dropbox->provider()->parseWebhook([], 'not an event');
    }

    #[Test]
    public function the_webhook_response_is_the_200_dropbox_sign_requires(): void
    {
        $response = $this->dropbox->provider()->webhookResponse([]);

        self::assertSame(200, $response->status);
        self::assertSame('Hello API Event Received', $response->body);
        self::assertSame(DropboxProvider::WEBHOOK_RESPONSE, $response->body);
    }

    #[Test]
    public function handle_webhook_answers_every_call_with_the_required_body(): void
    {
        $esign = new Esign(['default' => 'dropbox', 'providers' => ['dropbox' => ['api_key' => FakeDropbox::API_KEY]]]);
        $esign->extend('dropbox', fn () => $this->dropbox->provider());

        $valid = $esign->handleWebhook([], (string) json_encode($this->event()));
        self::assertTrue($valid->isValid());
        self::assertSame('dropbox:abc123', (string) $valid->ref());
        self::assertSame('Hello API Event Received', $valid->response->body);

        $forged = $esign->handleWebhook([], (string) json_encode($this->event(key: 'forged')));
        self::assertFalse($forged->isValid());
        self::assertInstanceOf(InvalidWebhook::class, $forged->error);
        self::assertSame(200, $forged->response->status);
        self::assertSame('Hello API Event Received', $forged->response->body);
    }

    #[Test]
    public function the_core_refuses_identity_methods_dropbox_sign_lacks_before_any_call(): void
    {
        $esign = new Esign(['default' => 'dropbox', 'providers' => ['dropbox' => ['api_key' => FakeDropbox::API_KEY]]]);
        $esign->extend('dropbox', fn () => $this->dropbox->provider());

        try {
            $esign->send($this->envelope(new Signer('Omar', 'omar@example.com', IdentityMethod::UaePass)));
            self::fail('Expected an exception');
        } catch (EnvelopeNotSupported $e) {
            self::assertStringContainsString('uae_pass', $e->getMessage());
        }
        self::assertSame([], $this->dropbox->calls);
    }
}
