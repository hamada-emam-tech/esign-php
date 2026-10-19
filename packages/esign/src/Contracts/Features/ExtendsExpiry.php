<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts\Features;

use DateTimeInterface;

interface ExtendsExpiry
{
    /** Move the deadline of every open invitation in the envelope. */
    public function extend(string $envelopeId, DateTimeInterface $until): void;
}
