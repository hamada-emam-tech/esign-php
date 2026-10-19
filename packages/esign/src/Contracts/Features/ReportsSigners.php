<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts\Features;

use HamadaEmamTech\Esign\Envelope\EnvelopeDetails;

interface ReportsSigners
{
    /** The envelope's status plus each signer's own status and signing link. */
    public function details(string $envelopeId): EnvelopeDetails;
}
