<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Testing;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;

final class FakeProviderFactory implements ProviderFactory
{
    public function create(array $config): SignatureProvider
    {
        return new FakeProvider((string) ($config['webhook_secret'] ?? 'fake-secret'));
    }
}
