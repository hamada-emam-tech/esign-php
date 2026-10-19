<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts;

use HamadaEmamTech\Esign\Capabilities\Capabilities;

/** A provider that says what it supports. Every driver in this family implements it. */
interface HasCapabilities
{
    public function capabilities(): Capabilities;
}
