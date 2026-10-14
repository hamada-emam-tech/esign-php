<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

/** Where a signature goes, in PDF points from the top-left of the page. Pages start at 1. */
final readonly class SignatureField
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width = 150,
        public float $height = 50,
        public int $documentIndex = 0,
    ) {
    }
}
