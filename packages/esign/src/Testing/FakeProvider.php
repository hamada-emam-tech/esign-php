<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Testing;

use DateTimeInterface;
use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\ExtendsExpiry;
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
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignerState;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use PHPUnit\Framework\Assert;

/**
 * In-memory provider for your tests. Always available as "fake", no install needed.
 *
 *     $fake = $esign->provider('fake');
 *     $sent = $fake->send($envelope);
 *     $fake->sign($sent->envelopeId);          // simulate everyone signing
 *     $fake->assertSent(fn (Envelope $e) => $e->signers[0]->nationalId === '1012345678');
 */
final class FakeProvider implements SignatureProvider, HasCapabilities, SendsReminders, ExtendsExpiry, ManagesWebhooks, ReportsSigners, RecognizesWebhooks
{
    /** @var array<string, array{envelope: Envelope, status: EnvelopeStatus}> */
    private array $envelopes = [];

    /** @var list<array{envelope: string, email: string}> */
    public array $reminders = [];

    /** @var array<string, string> webhook ID => URL */
    private array $webhooks = [];

    /** Make download() fail like a provider outage, to test retries. */
    public bool $failDownloads = false;

    private int $sequence = 0;

    public function __construct(
        private readonly string $webhookSecret = 'fake-secret',
        private readonly string $name = 'fake',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Everything, so tests can exercise every path. */
    public function capabilities(): Capabilities
    {
        return new Capabilities(IdentityMethod::cases(), Feature::cases());
    }

    public function details(string $envelopeId): EnvelopeDetails
    {
        $entry = $this->entry($envelopeId);
        $signerStatus = match ($entry['status']) {
            EnvelopeStatus::Signed => SignerStatus::Signed,
            EnvelopeStatus::Declined => SignerStatus::Declined,
            default => SignerStatus::Pending,
        };
        $signers = array_map(
            fn ($s) => new SignerState($s->email, $s->name, $signerStatus, "https://fake.esign.test/sign/{$envelopeId}/" . rawurlencode($s->email), $s->order),
            $entry['envelope']->signers,
        );

        return new EnvelopeDetails(new EnvelopeRef($this->name, $envelopeId), $entry['status'], $signers);
    }

    public function remind(string $envelopeId, string $signerEmail): void
    {
        $this->entry($envelopeId);
        $this->reminders[] = ['envelope' => $envelopeId, 'email' => $signerEmail];
    }

    public function extend(string $envelopeId, DateTimeInterface $until): void
    {
        $this->entry($envelopeId);
    }

    public function registerWebhook(string $url, #[\SensitiveParameter] ?string $secret = null): string
    {
        $id = 'fake-webhook-' . (count($this->webhooks) + 1);
        $this->webhooks[$id] = $url;

        return $id;
    }

    public function deleteWebhook(string $webhookId): void
    {
        unset($this->webhooks[$webhookId]);
    }

    public function webhooks(): array
    {
        $list = [];
        foreach ($this->webhooks as $id => $url) {
            $list[] = ['id' => $id, 'url' => $url];
        }

        return $list;
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        $id = $this->name . '-' . ++$this->sequence;
        $this->envelopes[$id] = ['envelope' => $envelope, 'status' => EnvelopeStatus::Sent];
        $urls = [];
        foreach ($envelope->signers as $signer) {
            $urls[$signer->email] = "https://fake.esign.test/sign/{$id}/" . rawurlencode($signer->email);
        }

        return new SentEnvelope($this->name, $id, EnvelopeStatus::Sent, $urls);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        return $this->entry($envelopeId)['status'];
    }

    public function download(string $envelopeId): string
    {
        if ($this->status($envelopeId) !== EnvelopeStatus::Signed) {
            throw new EsignException("Envelope {$envelopeId} is not signed yet.");
        }
        if ($this->failDownloads) {
            throw new \HamadaEmamTech\Esign\Exceptions\ProviderError($this->name, 'Simulated download failure.', 503);
        }

        return "%PDF-1.7\n% fake sealed document for {$envelopeId}\n";
    }

    public function cancel(string $envelopeId): void
    {
        $this->setStatus($envelopeId, EnvelopeStatus::Cancelled);
    }

    public function recognizesWebhook(array $headers, string $rawBody): bool
    {
        return Headers::has($headers, 'X-Fake-Signature');
    }

    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $given = $headers['X-Fake-Signature'] ?? $headers['x-fake-signature'] ?? '';
        $given = is_array($given) ? ($given[0] ?? '') : $given;
        if (! hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), (string) $given)) {
            throw new InvalidWebhook('Fake webhook signature does not match.');
        }
        $payload = json_decode($rawBody, true) ?? [];

        return new WebhookEvent($this->name, (string) ($payload['envelope_id'] ?? ''), $payload['event_id'] ?? null, $payload);
    }

    // ---- test helpers ----------------------------------------------------------

    public function sign(string $envelopeId): void
    {
        $this->setStatus($envelopeId, EnvelopeStatus::Signed);
    }

    public function decline(string $envelopeId): void
    {
        $this->setStatus($envelopeId, EnvelopeStatus::Declined);
    }

    /**
     * Build headers + body for a webhook call, signed the way parseWebhook() expects.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    public function webhookFor(string $envelopeId, string $eventId = 'evt-1'): array
    {
        $body = (string) json_encode(['envelope_id' => $envelopeId, 'event_id' => $eventId]);

        return [['X-Fake-Signature' => hash_hmac('sha256', $body, $this->webhookSecret)], $body];
    }

    /** @param (callable(Envelope): bool)|null $matching */
    public function assertSent(?callable $matching = null): void
    {
        $hits = array_filter($this->envelopes, fn (array $e) => $matching === null || $matching($e['envelope']));
        Assert::assertNotEmpty($hits, 'No matching envelope was sent through the fake provider.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->envelopes, 'Envelopes were sent through the fake provider.');
    }

    private function setStatus(string $envelopeId, EnvelopeStatus $status): void
    {
        $this->entry($envelopeId);
        $this->envelopes[$envelopeId]['status'] = $status;
    }

    /** @return array{envelope: Envelope, status: EnvelopeStatus} */
    private function entry(string $envelopeId): array
    {
        return $this->envelopes[$envelopeId] ?? throw new EsignException("Unknown envelope {$envelopeId}.");
    }
}
