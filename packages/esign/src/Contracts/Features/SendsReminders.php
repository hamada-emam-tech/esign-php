<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts\Features;

interface SendsReminders
{
    /** Email the signing link to a signer again, without adding them twice. */
    public function remind(string $envelopeId, string $signerEmail): void;
}
