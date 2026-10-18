<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

use HamadaEmamTech\Esign\Catalog\ProviderCatalog;

final class ProviderNotInstalled extends EsignException
{
    public static function named(string $provider): self
    {
        $entry = ProviderCatalog::find($provider);

        if ($entry === null) {
            return new self(sprintf(
                'Unknown provider "%s". Known providers: %s. To use your own, register it with Esign::extend().',
                $provider,
                implode(', ', array_keys(ProviderCatalog::all())),
            ));
        }

        if (! $entry->isReleased()) {
            return new self(sprintf(
                'Provider "%s" (%s) is planned but not released yet. Roadmap: %s',
                $entry->name,
                $entry->label,
                ProviderCatalog::ROADMAP_URL,
            ));
        }

        return new self(sprintf(
            'Provider "%s" is not installed. Run: vendor/bin/esign add %s (or: composer require %s)',
            $entry->name,
            $entry->name,
            $entry->package,
        ));
    }
}
