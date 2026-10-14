<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

final class ProviderNotInstalled extends EsignException
{
    public static function named(string $provider): self
    {
        return new self(sprintf(
            'Provider "%s" is not installed. Run: composer require hamada-emam-tech/esign-%s',
            $provider,
            $provider,
        ));
    }
}
