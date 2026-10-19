<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

use HamadaEmamTech\Esign\Capabilities\Feature;

/** You asked a provider for something it can't do. Check Esign::supports() first. */
final class UnsupportedFeature extends EsignException
{
    public static function named(string $provider, Feature $feature): self
    {
        return new self(sprintf('Provider "%s" does not support %s.', $provider, str_replace('_', ' ', $feature->value)));
    }
}
