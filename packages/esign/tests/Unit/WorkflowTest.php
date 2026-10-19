<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Tests\Unit;

use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\CustomizesWebhookResponse;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\EnvelopeNotSupported;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\UnsupportedFeature;
use HamadaEmamTech\Esign\Testing\FakeProvider;
use HamadaEmamTech\Esign\Webhook\WebhookResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The guarantees that make every provider interchangeable. */
final class WorkflowTest extends TestCase
{
    private function esign(string $default = 'alpha'): Esign
    {
        $alpha = new FakeProvider('alpha-secret', 'alpha');
        $beta = new FakeProvider('beta-secret', 'beta');

        return (new Esign(['default' => $default, 'providers' => ['alpha' => [], 'beta' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('alpha', fn () => $alpha)
            ->extend('beta', fn () => $beta);
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope([new Document('contract.pdf', '%PDF-1.7 test')], $signers === [] ? [Signer::email('Sara', 'sara@example.sa')] : array_values($signers));
    }

    #[Test]
    public function a_stored_reference_reaches_the_provider_that_sent_it_after_the_default_changes(): void
    {
        $esign = $this->esign('alpha');
        $ref = (string) $esign->send($this->envelope())->ref();
        self::assertStringStartsWith('alpha:', $ref);

        // Later the app switches its default provider. The old envelope must keep working.
        $switched = $this->esign('beta');
        $switched->extend('alpha', fn () => $esign->provider('alpha'));

        self::assertSame(EnvelopeStatus::Sent, $switched->status($ref));
        /** @var FakeProvider $alpha */
        $alpha = $esign->provider('alpha');
        $alpha->sign(EnvelopeRef::parse($ref)->envelopeId);
        self::assertSame(EnvelopeStatus::Signed, $switched->status($ref));
        self::assertStringStartsWith('%PDF', $switched->download($ref));
    }

    #[Test]
    public function references_sent_envelopes_and_bare_ids_are_all_accepted(): void
    {
        $esign = $this->esign();
        $sent = $esign->send($this->envelope());

        self::assertSame(EnvelopeStatus::Sent, $esign->status($sent));
        self::assertSame(EnvelopeStatus::Sent, $esign->status($sent->ref()));
        self::assertSame(EnvelopeStatus::Sent, $esign->status((string) $sent->ref()));
        self::assertSame(EnvelopeStatus::Sent, $esign->status($sent->envelopeId));   // bare ID: default provider
    }

    #[Test]
    public function every_envelope_is_checked_against_the_providers_capabilities_before_sending(): void
    {
        $limited = new class () extends StubProvider implements HasCapabilities {
            public function capabilities(): Capabilities
            {
                return new Capabilities([IdentityMethod::Email], [], maxDocuments: 1);
            }
        };
        $esign = (new Esign(['default' => 'stub', 'providers' => ['stub' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('stub', fn () => $limited);

        $envelope = new Envelope(
            [new Document('a.pdf', '%PDF a'), new Document('b.pdf', '%PDF b')],
            [
                Signer::nafath('1012345678', 'Ahmed', 'ahmed@example.sa', 1, [new SignatureField(1, 10, 10)]),
                Signer::email('Sara', 'sara@example.sa', 2),
            ],
        );

        try {
            $esign->send($envelope);
            self::fail('Expected EnvelopeNotSupported');
        } catch (EnvelopeNotSupported $e) {
            self::assertSame('stub', $e->provider);
            self::assertCount(4, $e->problems);    // documents, identity, fields, order: all at once
        }
        self::assertSame(0, $limited->sends, 'nothing may reach the provider');
    }

    #[Test]
    public function a_signer_limit_is_enforced_by_the_core(): void
    {
        $single = new class () extends StubProvider implements HasCapabilities {
            public function capabilities(): Capabilities
            {
                return new Capabilities(IdentityMethod::cases(), Feature::cases(), maxSigners: 1);
            }
        };
        $esign = (new Esign(['default' => 'stub', 'providers' => ['stub' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('stub', fn () => $single);

        $this->expectException(EnvelopeNotSupported::class);
        $this->expectExceptionMessage('at most 1 signer(s)');
        $esign->send($this->envelope(Signer::email('A', 'a@example.sa'), Signer::email('B', 'b@example.sa')));
    }

    #[Test]
    public function an_unsupported_feature_fails_the_same_way_for_every_provider(): void
    {
        $esign = (new Esign(['default' => 'stub', 'providers' => ['stub' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('stub', fn () => new StubProvider());

        self::assertFalse($esign->supports(Feature::Reminders));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('Provider "stub" does not support reminders.');
        $esign->remind('stub:env-1', 'sara@example.sa');
    }

    #[Test]
    public function supports_never_claims_a_feature_the_driver_does_not_implement(): void
    {
        $boastful = new class () extends StubProvider implements HasCapabilities {
            public function capabilities(): Capabilities
            {
                return new Capabilities([IdentityMethod::Email], [Feature::Reminders, Feature::SigningOrder]);
            }
        };
        $esign = (new Esign(['default' => 'stub', 'providers' => ['stub' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('stub', fn () => $boastful);

        self::assertFalse($esign->supports(Feature::Reminders));     // declared, but no SendsReminders
        self::assertTrue($esign->supports(Feature::SigningOrder));   // no method behind it: the declaration counts
    }

    #[Test]
    public function optional_features_run_through_one_api(): void
    {
        $esign = $this->esign();
        $sent = $esign->send($this->envelope(Signer::email('Sara', 'sara@example.sa'), Signer::email('Omar', 'omar@example.sa', 2)));

        $esign->remind($sent, 'omar@example.sa');
        $esign->extendDeadline($sent, new \DateTimeImmutable('+7 days'));
        $details = $esign->details($sent);

        self::assertSame('alpha', $details->ref->provider);
        self::assertCount(2, $details->pending());
        self::assertSame(SignerStatus::Pending, $details->signers[0]->status);
        /** @var FakeProvider $alpha */
        $alpha = $esign->provider('alpha');
        self::assertSame([['envelope' => $sent->envelopeId, 'email' => 'omar@example.sa']], $alpha->reminders);

        $id = $esign->registerWebhook('https://app.example.sa/hooks', 'secret');
        self::assertSame([['id' => $id, 'url' => 'https://app.example.sa/hooks']], $esign->webhooks());
        $esign->deleteWebhook($id);
        self::assertSame([], $esign->webhooks());
    }

    #[Test]
    public function a_bad_webhook_never_throws_and_still_gets_a_200(): void
    {
        $result = $this->esign()->handleWebhook(['X-Fake-Signature' => 'forged'], '{"envelope_id":"alpha-1"}');

        self::assertFalse($result->isValid());
        self::assertInstanceOf(InvalidWebhook::class, $result->error);
        self::assertNull($result->ref());
        self::assertSame(200, $result->response->status);
    }

    #[Test]
    public function a_good_webhook_says_which_envelope_to_refresh(): void
    {
        $esign = $this->esign();
        $sent = $esign->send($this->envelope());
        /** @var FakeProvider $alpha */
        $alpha = $esign->provider('alpha');
        [$headers, $body] = $alpha->webhookFor($sent->envelopeId);

        $result = $esign->handleWebhook($headers, $body, 'alpha');

        self::assertTrue($result->isValid());
        self::assertSame((string) $sent->ref(), (string) $result->ref());
    }

    #[Test]
    public function a_provider_that_needs_a_special_webhook_answer_gets_it(): void
    {
        $picky = new class () extends StubProvider implements CustomizesWebhookResponse {
            public function webhookResponse(array $headers): WebhookResponse
            {
                return new WebhookResponse(200, 'Hello API Event Received', ['Content-Type' => 'text/plain']);
            }
        };
        $esign = (new Esign(['default' => 'stub', 'providers' => ['stub' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('stub', fn () => $picky);

        $result = $esign->handleWebhook([], '{}');

        self::assertSame('Hello API Event Received', $result->response->body);   // even for a rejected call
    }
}

/** A provider with the bare contract only: no capabilities, no optional features. */
class StubProvider implements SignatureProvider
{
    public int $sends = 0;

    public function name(): string
    {
        return 'stub';
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        ++$this->sends;

        return new SentEnvelope('stub', 'env-1');
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        return EnvelopeStatus::Sent;
    }

    public function download(string $envelopeId): string
    {
        return '%PDF';
    }

    public function cancel(string $envelopeId): void
    {
    }

    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        throw new InvalidWebhook('stub refuses every webhook');
    }
}
