<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Discovery;

use Composer\Autoload\ClassLoader;
use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\ProviderNotInstalled;
use HamadaEmamTech\Esign\Testing\FakeProviderFactory;
use ReflectionClass;

/**
 * Knows which providers are installed.
 *
 * Provider packages declare their factory in composer.json under extra.esign.providers.
 * The registry reads Composer's vendor/composer/installed.json once, the same way
 * Laravel package discovery works, so `composer require hamada-emam-tech/esign-sadq`
 * is all it takes. Anything can also be registered by hand with register().
 */
final class ProviderRegistry
{
    /** @var array<string, class-string<ProviderFactory>|ProviderFactory|callable(array<string, mixed>): \HamadaEmamTech\Esign\Contracts\SignatureProvider> */
    private array $factories = ['fake' => FakeProviderFactory::class];

    private bool $discovered = false;

    public function __construct(private readonly ?string $installedJsonPath = null)
    {
    }

    /** @param class-string<ProviderFactory>|ProviderFactory|callable(array<string, mixed>): \HamadaEmamTech\Esign\Contracts\SignatureProvider $factory */
    public function register(string $name, string|ProviderFactory|callable $factory): void
    {
        $this->factories[strtolower($name)] = $factory;
    }

    public function has(string $name): bool
    {
        $this->discover();

        return isset($this->factories[strtolower($name)]);
    }

    /** @return list<string> */
    public function names(): array
    {
        $this->discover();

        return array_keys($this->factories);
    }

    /** @param array<string, mixed> $config */
    public function create(string $name, array $config): \HamadaEmamTech\Esign\Contracts\SignatureProvider
    {
        $this->discover();
        $factory = $this->factories[strtolower($name)] ?? throw ProviderNotInstalled::named($name);

        if (is_string($factory)) {
            if (! is_subclass_of($factory, ProviderFactory::class)) {
                throw new EsignException("Factory {$factory} for provider \"{$name}\" must implement " . ProviderFactory::class . '.');
            }
            $factory = new $factory();
        }

        return $factory instanceof ProviderFactory ? $factory->create($config) : $factory($config);
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        $path = $this->installedJsonPath ?? self::defaultInstalledJsonPath();
        if ($path === null || ! is_file($path)) {
            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $packages = $data['packages'] ?? $data ?? [];   // Composer 2 wraps the list in "packages"

        foreach ($packages as $package) {
            foreach ($package['extra']['esign']['providers'] ?? [] as $name => $factoryClass) {
                // Explicit registrations win over discovered ones.
                $this->factories[strtolower((string) $name)] ??= $factoryClass;
            }
        }
    }

    private static function defaultInstalledJsonPath(): ?string
    {
        if (! class_exists(ClassLoader::class)) {
            return null;
        }
        $vendorDir = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);

        return $vendorDir . '/composer/installed.json';
    }
}
