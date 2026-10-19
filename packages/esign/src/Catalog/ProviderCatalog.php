<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Catalog;

/**
 * Every provider in the esign family, so the core can say what exists and how to install it.
 * The order and status follow docs/providers.md. A provider moves to Available when its
 * package is on Packagist.
 */
final class ProviderCatalog
{
    public const ROADMAP_URL = 'https://github.com/hamada-emam-tech/esign-php/blob/main/docs/providers.md';

    /** @var array<string, CatalogEntry>|null */
    private static ?array $entries = null;

    /** @return array<string, CatalogEntry> keyed by provider name */
    public static function all(): array
    {
        return self::$entries ??= self::build([
            // name        label                         region   status
            ['fake',     'Fake (for tests)',             'any',    ProviderStatus::BuiltIn],
            ['sadq',     'SADQ',                         'KSA',    ProviderStatus::Available],
            ['signit',   'Signit',                       'KSA',    ProviderStatus::Available],
            ['docusign', 'DocuSign',                     'global', ProviderStatus::Available],
            ['zoho',     'Zoho Sign',                    'global', ProviderStatus::Available],
            ['dropbox',  'Dropbox Sign',                 'global', ProviderStatus::Available],
            ['adobe',    'Adobe Acrobat Sign',           'global', ProviderStatus::Available],
            ['boldsign', 'BoldSign',                     'global', ProviderStatus::Available],
            ['emdha',    'emdha',                        'KSA',    ProviderStatus::Planned],
            ['uaepass',  'UAE PASS',                     'UAE',    ProviderStatus::Available],
            ['csc',      'Cloud Signature Consortium',   'EU+',    ProviderStatus::Planned],
        ]);
    }

    public static function find(string $name): ?CatalogEntry
    {
        return self::all()[strtolower($name)] ?? null;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: ProviderStatus}> $rows
     *
     * @return array<string, CatalogEntry>
     */
    private static function build(array $rows): array
    {
        $entries = [];
        foreach ($rows as [$name, $label, $region, $status]) {
            $package = $status === ProviderStatus::BuiltIn ? 'hamada-emam-tech/esign' : "hamada-emam-tech/esign-{$name}";
            $entries[$name] = new CatalogEntry($name, $package, $label, $region, $status);
        }

        return $entries;
    }
}
