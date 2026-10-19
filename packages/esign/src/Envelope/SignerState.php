<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

/** One signer, as the provider sees them now. */
final readonly class SignerState
{
    public function __construct(
        public string $email,
        public string $name,
        public SignerStatus $status,
        public ?string $signingUrl = null,
        public ?int $order = null,
    ) {
    }
}
