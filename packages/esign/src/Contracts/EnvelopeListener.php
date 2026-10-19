<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts;

use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;

/**
 * Your code that reacts to envelope changes, the same for every provider. EnvelopeSynchronizer
 * calls it after asking the provider for the real status, never on the strength of a webhook body.
 */
interface EnvelopeListener
{
    /** The status differs from the one you passed in as known. */
    public function statusChanged(EnvelopeRef $ref, EnvelopeStatus $status, ?EnvelopeStatus $previous): void;

    /** Everyone signed. $pdf is the signed document; store it. Called once per sync that reaches Signed. */
    public function signed(EnvelopeRef $ref, string $pdf): void;
}
