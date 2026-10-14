<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Testing;

use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Envelope\Envelope;
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
final class FakeProvider implements SignatureProvider
{
    /** @var array<string, array{envelope: Envelope, status: EnvelopeStatus}> */
    private array $envelopes = [];

    private int $sequence = 0;

    public function __construct(private readonly string $webhookSecret = 'fake-secret')
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        $id = 'fake-' . ++$this->sequence;
        $this->envelopes[$id] = ['envelope' => $envelope, 'status' => EnvelopeStatus::Sent];
        $urls = [];
        foreach ($envelope->signers as $signer) {
            $urls[$signer->email] = "https://fake.esign.test/sign/{$id}/" . rawurlencode($signer->email);
        }

        return new SentEnvelope('fake', $id, EnvelopeStatus::Sent, $urls);
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

        return "%PDF-1.7\n% fake sealed document for {$envelopeId}\n";
    }

    public function cancel(string $envelopeId): void
    {
        $this->setStatus($envelopeId, EnvelopeStatus::Cancelled);
    }

    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        $given = $headers['X-Fake-Signature'] ?? $headers['x-fake-signature'] ?? '';
        $given = is_array($given) ? ($given[0] ?? '') : $given;
        if (! hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), (string) $given)) {
            throw new InvalidWebhook('Fake webhook signature does not match.');
        }
        $payload = json_decode($rawBody, true) ?? [];

        return new WebhookEvent('fake', (string) ($payload['envelope_id'] ?? ''), $payload['event_id'] ?? null, $payload);
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
