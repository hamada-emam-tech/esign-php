<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Tests\Fixtures;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Testing\FakeProvider;

/** Stands in for a real provider package's factory in discovery tests. */
final class DummyFactory implements ProviderFactory
{
    /** @var array<string, mixed> */
    public static array $lastConfig = [];

    public function create(array $config): SignatureProvider
    {
        self::$lastConfig = $config;

        return new FakeProvider();
    }
}
