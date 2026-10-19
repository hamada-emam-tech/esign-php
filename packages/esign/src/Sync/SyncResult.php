<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sync;

use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;

final readonly class SyncResult
{
    public function __construct(
        public EnvelopeRef $ref,
        public EnvelopeStatus $status,
        public ?EnvelopeStatus $previous,
        /** True when the status differs from $previous and the listener was told. */
        public bool $changed,
        /** True when the signed PDF was downloaded and handed to the listener. */
        public bool $downloaded,
    ) {
    }
}
