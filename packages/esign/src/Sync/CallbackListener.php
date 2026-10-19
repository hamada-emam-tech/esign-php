<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sync;

use Closure;
use HamadaEmamTech\Esign\Contracts\EnvelopeListener;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;

/**
 * An EnvelopeListener made of two closures, for when a class is too much:
 *
 *     new CallbackListener(
 *         statusChanged: fn (EnvelopeRef $ref, EnvelopeStatus $status) => Contract::byRef($ref)->update(['status' => $status->value]),
 *         signed: fn (EnvelopeRef $ref, string $pdf) => Storage::put("signed/{$ref->envelopeId}.pdf", $pdf),
 *     );
 */
final readonly class CallbackListener implements EnvelopeListener
{
    /**
     * @param (Closure(EnvelopeRef, EnvelopeStatus, ?EnvelopeStatus): void)|null $statusChanged
     * @param (Closure(EnvelopeRef, string): void)|null                          $signed
     */
    public function __construct(
        private ?Closure $statusChanged = null,
        private ?Closure $signed = null,
    ) {
    }

    public function statusChanged(EnvelopeRef $ref, EnvelopeStatus $status, ?EnvelopeStatus $previous): void
    {
        if ($this->statusChanged !== null) {
            ($this->statusChanged)($ref, $status, $previous);
        }
    }

    public function signed(EnvelopeRef $ref, string $pdf): void
    {
        if ($this->signed !== null) {
            ($this->signed)($ref, $pdf);
        }
    }
}
