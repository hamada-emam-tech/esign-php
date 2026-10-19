<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

final readonly class SentEnvelope
{
    /** @param array<string, string> $signingUrls signer email => signing link, when the provider returns links */
    public function __construct(
        public string $provider,
        public string $envelopeId,
        public EnvelopeStatus $status = EnvelopeStatus::Sent,
        public array $signingUrls = [],
    ) {
    }

    /** Store (string) $sent->ref() rather than the bare envelope ID. */
    public function ref(): EnvelopeRef
    {
        return new EnvelopeRef($this->provider, $this->envelopeId);
    }
}
