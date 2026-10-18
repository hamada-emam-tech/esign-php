<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Catalog;

/** One provider the package family knows about, released or not. */
final readonly class CatalogEntry
{
    public function __construct(
        public string $name,
        public string $package,
        public string $label,
        public string $region,
        public ProviderStatus $status,
    ) {
    }

    public function isReleased(): bool
    {
        return $this->status !== ProviderStatus::Planned;
    }
}
