<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

final class ProviderNotConfigured extends EsignException
{
    public static function named(string $provider): self
    {
        return new self(sprintf('Provider "%s" is installed but has no entry under "providers" in the Esign config.', $provider));
    }

    public static function noDefault(): self
    {
        return new self('No provider name given and no "default" provider in the Esign config.');
    }
}
