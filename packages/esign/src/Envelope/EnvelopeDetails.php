<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

final readonly class EnvelopeDetails
{
    /** @param list<SignerState> $signers the people you invited, in order */
    public function __construct(
        public EnvelopeRef $ref,
        public EnvelopeStatus $status,
        public array $signers,
    ) {
    }

    /** @return list<SignerState> */
    public function pending(): array
    {
        return array_values(array_filter($this->signers, fn (SignerState $s) => $s->status === SignerStatus::Pending));
    }
}
