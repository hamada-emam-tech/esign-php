<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts;

/**
 * Every provider package ships one factory and declares it in its composer.json:
 *
 *     "extra": { "esign": { "providers": { "sadq": "Vendor\\Sadq\\SadqFactory" } } }
 *
 * The core discovers it from Composer's installed packages, so installing the
 * package is enough to make the provider available.
 */
interface ProviderFactory
{
    /** @param array<string, mixed> $config the provider's block from the Esign config */
    public function create(array $config): SignatureProvider;
}
