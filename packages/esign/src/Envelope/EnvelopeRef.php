<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

use InvalidArgumentException;

/**
 * Which provider holds an envelope, and its ID there. Store the string form ("sadq:4f1c…")
 * in one column: every later call is routed to the provider that created the envelope, so
 * changing the default provider never breaks envelopes that are already out for signing.
 */
final readonly class EnvelopeRef
{
    public function __construct(public string $provider, public string $envelopeId)
    {
        if ($provider === '' || str_contains($provider, ':') || $envelopeId === '') {
            throw new InvalidArgumentException('An envelope reference needs a provider name without ":" and an envelope ID.');
        }
    }

    /** Parse "provider:envelopeId". */
    public static function parse(string $ref): self
    {
        $parts = explode(':', $ref, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("\"{$ref}\" is not an envelope reference. Expected \"provider:envelopeId\".");
        }

        return new self(strtolower($parts[0]), $parts[1]);
    }

    public function __toString(): string
    {
        return $this->provider . ':' . $this->envelopeId;
    }
}
