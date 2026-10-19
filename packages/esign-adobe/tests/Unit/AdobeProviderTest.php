<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe\Tests\Unit;

use HamadaEmamTech\Esign\Adobe\AdobeOptions;
use HamadaEmamTech\Esign\Adobe\AdobeProvider;
use HamadaEmamTech\Esign\Support\PdfPageSize;
use HamadaEmamTech\Esign\Adobe\Tests\Support\ArrayCache;
use HamadaEmamTech\Esign\Adobe\Tests\Support\FakeAdobe;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdobeProviderTest extends TestCase
{
    private FakeAdobe $adobe;

    protected function setUp(): void
    {
        $this->adobe = new FakeAdobe();
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope(
            [new Document('contract.pdf', FakeAdobe::PDF)],
            $signers === [] ? [Signer::email('Ahmed Ali', 'ahmed@example.test', 1), Signer::email('Sara', 'sara@example.test', 2)] : array_values($signers),
            subject: 'Please sign',
            message: 'The agreement we discussed.',
            metadata: ['reference' => 'contract-42'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function apiCalls(): array
    {
        return array_values(array_filter($this->adobe->calls, fn (array $c) => str_starts_with($c['path'], '/api/rest/v6/') && $c['path'] !== '/api/rest/v6/baseUris'));
    }

    // ---- base URI discovery -------------------------------------------------------------

    #[Test]
    public function the_access_point_is_discovered_once_and_every_call_goes_to_it(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->status($id);

        self::assertSame(1, $this->adobe->baseUriCalls);
        self::assertSame('https://api.adobesign.com/api/rest/v6/baseUris', $this->adobe->calls[0]['url']);
        foreach ($this->apiCalls() as $call) {
            self::assertStringStartsWith('https://api.na9.adobesign.com/api/rest/v6/', $call['url']);   // trailing slash handled
        }
    }

    #[Test]
    public function the_access_point_is_shared_through_the_cache(): void
    {
        $cache = new ArrayCache();
        $this->adobe->provider(cache: $cache)->send($this->envelope());
        (new FakeAdobe())->provider(cache: $cache);       // a second process
        $this->adobe->provider(cache: $cache)->send($this->envelope());

        self::assertSame(1, $this->adobe->baseUriCalls);
    }

    #[Test]
    public function a_configured_access_point_skips_discovery(): void
    {
        $this->adobe->provider(accessPoint: FakeAdobe::SHARD)->send($this->envelope());

        self::assertSame(0, $this->adobe->baseUriCalls);
    }

    #[Test]
    public function invalid_api_access_point_triggers_one_new_discovery(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->adobe->shard = FakeAdobe::OTHER_SHARD;           // the account moved

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        self::assertSame(2, $this->adobe->baseUriCalls);
        self::assertStringStartsWith('https://api.eu9.adobesign.com/', $this->adobe->calls[array_key_last($this->adobe->calls)]['url']);
    }

    // ---- send ----------------------------------------------------------------------

    #[Test]
    public function send_uploads_each_document_as_multipart_then_creates_the_agreement(): void
    {
        $sent = $this->adobe->provider()->send($this->envelope());

        self::assertSame('adobe', $sent->provider);
        self::assertSame(EnvelopeStatus::Sent, $sent->status);
        self::assertSame(['name' => 'contract.pdf', 'mime' => 'application/pdf', 'contents' => FakeAdobe::PDF], $this->adobe->transients['transient-1']);

        $body = $this->adobe->lastJson('POST', '/api/rest/v6/agreements');
        self::assertSame([['transientDocumentId' => 'transient-1']], $body['fileInfos']);
        self::assertSame('Please sign', $body['name']);
        self::assertSame('ESIGN', $body['signatureType']);
        self::assertSame('IN_PROCESS', $body['state']);
        self::assertSame('The agreement we discussed.', $body['message']);
        self::assertSame(['id' => 'contract-42'], $body['externalId']);
        self::assertSame([
            ['memberInfos' => [['email' => 'ahmed@example.test', 'name' => 'Ahmed Ali', 'securityOption' => ['authenticationMethod' => 'NONE']]], 'order' => 1, 'role' => 'SIGNER'],
            ['memberInfos' => [['email' => 'sara@example.test', 'name' => 'Sara', 'securityOption' => ['authenticationMethod' => 'NONE']]], 'order' => 2, 'role' => 'SIGNER'],
        ], $body['participantSetsInfo']);
    }

    #[Test]
    public function several_documents_become_several_file_infos(): void
    {
        $envelope = new Envelope([new Document('a.pdf', FakeAdobe::PDF), new Document('b.pdf', FakeAdobe::PDF)], [Signer::email('A', 'a@example.test')]);
        $this->adobe->provider()->send($envelope);

        self::assertCount(2, $this->adobe->lastJson('POST', '/api/rest/v6/agreements')['fileInfos']);
    }

    #[Test]
    public function signing_orders_are_renumbered_to_a_consecutive_sequence_keeping_ties(): void
    {
        $this->adobe->provider()->send($this->envelope(
            Signer::email('A', 'a@example.test', 2),
            Signer::email('B', 'b@example.test', 5),
            Signer::email('C', 'c@example.test', 5),
        ));

        self::assertSame([1, 2, 2], array_column($this->adobe->lastJson('POST', '/api/rest/v6/agreements')['participantSetsInfo'], 'order'));
    }

    #[Test]
    public function sms_otp_becomes_phone_authentication(): void
    {
        $this->adobe->provider()->send($this->envelope(new Signer('Omar', 'omar@example.test', IdentityMethod::SmsOtp, mobile: '+966 50 000 0000')));
        $member = $this->adobe->lastJson('POST', '/api/rest/v6/agreements')['participantSetsInfo'][0]['memberInfos'][0];

        self::assertSame(['authenticationMethod' => 'PHONE', 'phoneInfo' => ['countryCode' => '+966', 'phone' => '500000000']], $member['securityOption']);
    }

    #[Test]
    public function a_mobile_without_a_visible_country_code_is_refused_before_any_call(): void
    {
        try {
            $this->adobe->provider()->send($this->envelope(new Signer('Omar', 'omar@example.test', IdentityMethod::SmsOtp, mobile: '+966500000000')));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('+966 500000000', $e->getMessage());
        }
        self::assertSame([], $this->adobe->calls);
    }

    #[Test]
    public function an_unsupported_identity_method_is_refused_before_any_call(): void
    {
        try {
            $this->adobe->provider()->send($this->envelope(Signer::nafath('1012345678', 'Ahmed', 'ahmed@example.test')));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('"nafath"', $e->getMessage());
        }
        self::assertSame([], $this->adobe->calls);
    }

    // ---- field placement -------------------------------------------------------------

    #[Test]
    public function fields_are_placed_in_authoring_then_the_agreement_is_sent(): void
    {
        $sent = $this->adobe->provider()->send($this->envelope(
            Signer::email('Ahmed', 'ahmed@example.test', 1, [new SignatureField(1, 60.4, 700, 150, 50)]),
            Signer::email('Sara', 'sara@example.test', 2, [new SignatureField(2, 300, 100.5)]),
        ));

        self::assertSame('AUTHORING', $this->adobe->lastJson('POST', '/api/rest/v6/agreements')['state']);
        $id = $sent->envelopeId;
        self::assertSame([
            'POST /api/rest/v6/transientDocuments',
            'POST /api/rest/v6/agreements',
            "GET /api/rest/v6/agreements/{$id}/members",
            "PUT /api/rest/v6/agreements/{$id}/formFields",
            "PUT /api/rest/v6/agreements/{$id}/state",
        ], array_map(fn (array $c) => $c['method'] . ' ' . $c['path'], $this->apiCalls()));
        self::assertSame(['state' => 'IN_PROCESS'], $this->adobe->lastJson('PUT', "/api/rest/v6/agreements/{$id}/state"));
        self::assertSame('IN_PROCESS', $this->adobe->state($id));

        [$ahmed, $sara] = $this->adobe->lastJson('PUT', "/api/rest/v6/agreements/{$id}/formFields")['fields'];
        // A4 is 841.89 points high; Acrobat Sign measures "top" from the bottom of the page.
        self::assertSame([
            'name' => 'esign_signature_1_1', 'inputType' => 'SIGNATURE', 'contentType' => 'SIGNATURE', 'required' => true,
            'assignee' => 'ps-2-0',
            'locations' => [['pageNumber' => 1, 'top' => 141.89, 'left' => 60.4, 'width' => 150.0, 'height' => 50.0]],
        ], $ahmed);
        self::assertSame('ps-2-1', $sara['assignee']);
        self::assertSame(['pageNumber' => 2, 'top' => 741.39, 'left' => 300.0, 'width' => 150.0, 'height' => 50.0], $sara['locations'][0]);
    }

    #[Test]
    public function pages_of_mixed_sizes_need_a_configured_page_height(): void
    {
        $envelope = new Envelope([new Document('mixed.pdf', FakeAdobe::MIXED_PDF)], [Signer::email('A', 'a@example.test', 1, [new SignatureField(1, 10, 92)])]);

        try {
            $this->adobe->provider()->send($envelope);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('page_height', $e->getMessage());
        }
        self::assertSame([], $this->adobe->calls);

        $id = $this->adobe->provider(new AdobeOptions(pageHeight: 792))->send($envelope)->envelopeId;
        self::assertSame(700.0, $this->adobe->lastJson('PUT', "/api/rest/v6/agreements/{$id}/formFields")['fields'][0]['locations'][0]['top']);
    }

    #[Test]
    public function the_page_height_reader_only_answers_when_it_is_sure(): void
    {
        self::assertSame(841.89, PdfPageSize::uniformHeight(FakeAdobe::PDF));
        self::assertNull(PdfPageSize::uniformHeight(FakeAdobe::MIXED_PDF));
        self::assertNull(PdfPageSize::uniformHeight("%PDF-1.7\n<< /Type /Page /Rotate 90 /MediaBox [0 0 612 792] >>"));
        self::assertNull(PdfPageSize::uniformHeight("%PDF-1.7\n<< /Type /ObjStm >> stream..."));
        self::assertNull(PdfPageSize::uniformHeight('not a pdf'));
    }

    #[Test]
    public function fields_for_some_signers_but_not_others_are_refused_before_any_call(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('every signer a signature field, or none');
        try {
            $this->adobe->provider()->send($this->envelope(
                Signer::email('A', 'a@example.test', 1, [new SignatureField(1, 10, 10)]),
                Signer::email('B', 'b@example.test', 2),
            ));
        } finally {
            self::assertSame([], $this->adobe->calls);
        }
    }

    #[Test]
    public function a_field_on_a_later_document_is_refused_before_any_call(): void
    {
        $envelope = new Envelope(
            [new Document('a.pdf', FakeAdobe::PDF), new Document('b.pdf', FakeAdobe::PDF)],
            [Signer::email('A', 'a@example.test', 1, [new SignatureField(1, 10, 10, documentIndex: 1)])],
        );

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('first document');
        try {
            $this->adobe->provider()->send($envelope);
        } finally {
            self::assertSame([], $this->adobe->calls);
        }
    }

    #[Test]
    public function a_failed_field_placement_says_nothing_was_sent(): void
    {
        $this->adobe->failFormFields = true;

        try {
            $this->adobe->provider()->send($this->envelope(Signer::email('A', 'a@example.test', 1, [new SignatureField(1, 10, 10)])));
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('INVALID_FORM_FIELD_PROPERTY', $e->providerCode);
            self::assertStringContainsString('nobody was invited', $e->getMessage());
        }
        self::assertSame('AUTHORING', $this->adobe->state('CBJCHBCAABAA2'));
    }

    // ---- status --------------------------------------------------------------------

    #[Test]
    public function status_follows_the_participant_sets(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        $this->adobe->sign($id, 'ahmed@example.test');
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));
        $this->adobe->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function cancelled_is_told_apart_from_declined_and_failed_by_the_events(): void
    {
        $provider = $this->adobe->provider();
        [$a, $b, $c] = [$provider->send($this->envelope())->envelopeId, $provider->send($this->envelope())->envelopeId, $provider->send($this->envelope())->envelopeId];

        $provider->cancel($a);
        $this->adobe->reject($b, 'sara@example.test');
        $this->adobe->conversionProblem($c);

        self::assertSame(EnvelopeStatus::Cancelled, $provider->status($a));
        self::assertSame(EnvelopeStatus::Declined, $provider->status($b));
        self::assertSame(EnvelopeStatus::Failed, $provider->status($c));
    }

    #[Test]
    public function expired_maps_to_expired(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->adobe->expire($id);

        self::assertSame(EnvelopeStatus::Expired, $provider->status($id));
    }

    #[Test]
    public function an_unknown_status_fails_loudly_instead_of_guessing(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->adobe->forcedStatus = 'ARCHIVED';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('"ARCHIVED"');
        $provider->status($id);
    }

    #[Test]
    public function cancel_asks_acrobat_sign_to_notify_the_signers(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        self::assertSame(['state' => 'CANCELLED', 'agreementCancellationInfo' => ['notifyOthers' => true]], $this->adobe->lastJson('PUT', "/api/rest/v6/agreements/{$id}/state"));
    }

    // ---- details ---------------------------------------------------------------------

    #[Test]
    public function details_report_each_signer(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->adobe->sign($id, 'ahmed@example.test');

        $details = $provider->details($id);
        self::assertSame('adobe:' . $id, (string) $details->ref);
        self::assertSame(EnvelopeStatus::PartiallySigned, $details->status);
        self::assertSame(['ahmed@example.test', 'sara@example.test'], array_map(fn ($s) => $s->email, $details->signers));
        self::assertSame([SignerStatus::Signed, SignerStatus::Pending], array_map(fn ($s) => $s->status, $details->signers));
        self::assertSame(['Ahmed Ali', 'Sara'], array_map(fn ($s) => $s->name, $details->signers));
        self::assertSame([1, 2], array_map(fn ($s) => $s->order, $details->signers));

        $this->adobe->reject($id, 'sara@example.test');
        self::assertSame([SignerStatus::Signed, SignerStatus::Declined], array_map(fn ($s) => $s->status, $provider->details($id)->signers));
    }

    // ---- download ------------------------------------------------------------------

    #[Test]
    public function download_refuses_before_everyone_signed_without_fetching_the_pdf(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->adobe->sign($id, 'ahmed@example.test');

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('partially_signed', $e->getMessage());
        }
        self::assertNotContains("GET /api/rest/v6/agreements/{$id}/combinedDocument", $this->adobe->routes());
    }

    #[Test]
    public function download_returns_the_combined_document_once_signed(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->adobe->signAll($id);

        self::assertSame(FakeAdobe::SIGNED_PDF, $provider->download($id));
    }

    // ---- errors and authentication -------------------------------------------------------

    #[Test]
    public function errors_carry_the_http_status_and_acrobat_sign_code(): void
    {
        foreach ([false, true] as $reasonShape) {
            $this->adobe->reasonErrors = $reasonShape;
            try {
                $this->adobe->provider()->status('missing');
                self::fail('Expected an exception');
            } catch (ProviderError $e) {
                self::assertSame(404, $e->httpStatus);
                self::assertSame('INVALID_AGREEMENT_ID', $e->providerCode);
                self::assertStringContainsString('The Agreement ID specified is invalid.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function tokens_never_reach_an_error_message(): void
    {
        $this->adobe->echoTokenInErrors = true;

        try {
            $this->adobe->provider()->status('missing');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertStringNotContainsString(FakeAdobe::TOKEN, $e->getMessage());
            self::assertStringContainsString('***', $e->getMessage());
        }
    }

    #[Test]
    public function a_fixed_token_that_is_refused_is_not_retried(): void
    {
        $this->adobe->validTokens = [];

        try {
            $this->adobe->provider(accessPoint: FakeAdobe::SHARD)->status('x');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('INVALID_ACCESS_TOKEN', $e->providerCode);
        }
        self::assertCount(1, $this->adobe->calls);
    }

    #[Test]
    public function oauth_refresh_replaces_a_refused_token_and_retries_once(): void
    {
        $this->adobe->validTokens = [];          // the configured access token has expired
        $provider = $this->adobe->refreshingProvider('old-access-token');
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->status($id);

        self::assertSame(1, $this->adobe->refreshCalls);
        self::assertSame('https://api.na9.adobesign.com/oauth/v2/refresh', $this->adobe->calls[1]['url']);
    }

    #[Test]
    public function a_refreshed_token_is_reused_until_it_expires(): void
    {
        $now = 1_000_000;
        $provider = $this->adobe->refreshingProvider(clock: function () use (&$now) {
            return $now;
        });
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->status($id);
        self::assertSame(1, $this->adobe->refreshCalls);

        $now += 3600 - 59;      // inside the one-minute margin
        $provider->status($id);
        self::assertSame(2, $this->adobe->refreshCalls);
    }

    #[Test]
    public function a_failed_refresh_never_leaks_secrets(): void
    {
        $this->adobe->rejectRefresh = true;

        try {
            $this->adobe->refreshingProvider()->status('x');
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('INVALID_TOKEN', $e->providerCode);
            foreach ([FakeAdobe::REFRESH_TOKEN, FakeAdobe::CLIENT_SECRET] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    // ---- webhooks ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function hookBody(): array
    {
        return ['webhookId' => 'webhook-1', 'webhookNotificationId' => 'n-1', 'event' => 'AGREEMENT_WORKFLOW_COMPLETED', 'agreement' => ['id' => 'CBJ-9', 'status' => 'SIGNED']];
    }

    #[Test]
    public function a_webhook_with_the_right_client_id_says_which_agreement_changed(): void
    {
        $provider = $this->adobe->provider();

        foreach ([['X-AdobeSign-ClientId' => FakeAdobe::CLIENT_ID], ['x-adobesign-clientid' => [FakeAdobe::CLIENT_ID]]] as $headers) {
            $event = $provider->parseWebhook($headers, (string) json_encode($this->hookBody()));
            self::assertSame('CBJ-9', $event->envelopeId);
            self::assertSame('n-1', $event->eventId);
            self::assertSame('adobe', $event->provider);
        }
    }

    #[Test]
    public function a_wrong_or_missing_client_id_is_refused(): void
    {
        foreach ([['X-AdobeSign-ClientId' => 'someone-else'], []] as $headers) {
            try {
                $this->adobe->provider()->parseWebhook($headers, (string) json_encode($this->hookBody()));
                self::fail('Expected an exception');
            } catch (InvalidWebhook $e) {
                self::assertStringContainsString('X-AdobeSign-ClientId', $e->getMessage());
            }
        }
    }

    #[Test]
    public function without_a_configured_client_id_every_webhook_is_refused(): void
    {
        $provider = $this->adobe->provider(new AdobeOptions());

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('No Acrobat Sign webhook_client_id is configured');
        $provider->parseWebhook(['X-AdobeSign-ClientId' => ''], (string) json_encode($this->hookBody()));
    }

    #[Test]
    public function a_webhook_without_an_agreement_id_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->adobe->provider()->parseWebhook(['X-AdobeSign-ClientId' => FakeAdobe::CLIENT_ID], '{"event":"WIDGET_CREATED"}');
    }

    #[Test]
    public function the_webhook_response_echoes_the_client_id_in_header_and_body(): void
    {
        $response = $this->adobe->provider()->webhookResponse(['x-adobesign-clientid' => FakeAdobe::CLIENT_ID]);

        self::assertSame(200, $response->status);
        self::assertSame(FakeAdobe::CLIENT_ID, $response->headers[AdobeProvider::CLIENT_ID_HEADER]);
        self::assertSame([AdobeProvider::CLIENT_ID_BODY_KEY => FakeAdobe::CLIENT_ID], json_decode($response->body, true));
    }

    #[Test]
    public function an_unrecognised_client_id_gets_no_success_and_no_echo(): void
    {
        foreach ([[$this->adobe->provider(), ['X-AdobeSign-ClientId' => 'someone-else']], [$this->adobe->provider(new AdobeOptions()), ['X-AdobeSign-ClientId' => 'x']]] as [$provider, $headers]) {
            $response = $provider->webhookResponse($headers);
            self::assertSame(403, $response->status);
            self::assertSame([], $response->headers);
        }
    }

    #[Test]
    public function handle_webhook_answers_the_verification_of_intent_and_notifications(): void
    {
        $esign = new Esign(['default' => 'adobe', 'providers' => ['adobe' => []]]);
        $provider = $this->adobe->provider();
        $esign->extend('adobe', fn () => $provider);
        $headers = ['X-AdobeSign-ClientId' => FakeAdobe::CLIENT_ID];

        $intent = $esign->handleWebhook($headers, '', 'adobe');      // the GET Acrobat Sign makes on registration
        self::assertFalse($intent->isValid());
        self::assertSame(FakeAdobe::CLIENT_ID, $intent->response->headers['X-AdobeSign-ClientId']);

        $result = $esign->handleWebhook($headers, (string) json_encode($this->hookBody()), 'adobe');
        self::assertTrue($result->isValid());
        self::assertSame('adobe:CBJ-9', (string) $result->ref());
        self::assertSame(200, $result->response->status);
    }

    #[Test]
    public function webhooks_are_registered_listed_and_deleted(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->registerWebhook('https://app.example.test/webhooks/adobe');

        $body = $this->adobe->lastJson('POST', '/api/rest/v6/webhooks');
        self::assertSame('USER', $body['scope']);
        self::assertSame('ACTIVE', $body['state']);
        self::assertSame(['url' => 'https://app.example.test/webhooks/adobe'], $body['webhookUrlInfo']);
        self::assertContains('AGREEMENT_WORKFLOW_COMPLETED', $body['webhookSubscriptionEvents']);
        self::assertSame([['id' => $id, 'url' => 'https://app.example.test/webhooks/adobe']], $provider->webhooks());

        $provider->deleteWebhook($id);
        self::assertSame([], $provider->webhooks());
    }

    #[Test]
    public function a_webhook_secret_is_refused_because_acrobat_sign_has_none(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('no shared secret');
        $this->adobe->provider()->registerWebhook('https://app.example.test/webhooks/adobe', 'secret');
    }

    // ---- reminders -------------------------------------------------------------------

    #[Test]
    public function remind_targets_the_signers_participant_id(): void
    {
        $provider = $this->adobe->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->remind($id, 'SARA@example.test');

        self::assertSame(['recipientParticipantIds' => ['pm-2-1'], 'status' => 'ACTIVE'], $this->adobe->lastJson('POST', "/api/rest/v6/agreements/{$id}/reminders"));

        $this->expectException(EsignException::class);
        $provider->remind($id, 'nobody@example.test');
    }
}
