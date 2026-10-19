<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Testing;

use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\RecognizesWebhooks;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use PHPUnit\Framework\Assert;

/**
 * The checks every driver in this family must pass, so all providers behave as one.
 * Use it in each driver's tests:
 *
 *     use AssertsProviderConformance;
 *     $this->assertProviderConformance(new SadqFactory(), $config, 'sadq', __DIR__ . '/../../composer.json');
 */
trait AssertsProviderConformance
{
    /** @param array<string, mixed> $config a valid config for the factory; no network calls are made */
    public function assertProviderConformance(ProviderFactory $factory, array $config, string $name, string $composerJson): SignatureProvider
    {
        $provider = $factory->create($config);

        Assert::assertSame($name, $provider->name(), 'name() must match the name the provider is registered under.');
        Assert::assertInstanceOf(HasCapabilities::class, $provider, 'Every driver declares its capabilities.');
        Assert::assertInstanceOf(RecognizesWebhooks::class, $provider, 'Every driver recognizes its own webhooks, so one endpoint can serve all providers.');
        /** @var RecognizesWebhooks $provider */
        Assert::assertFalse($provider->recognizesWebhook([], ''), 'An empty request must not be claimed.');
        Assert::assertFalse($provider->recognizesWebhook(['User-Agent' => 'scanner'], '{"hello":"world"}'), 'Noise must not be claimed.');
        /** @var HasCapabilities&SignatureProvider $provider */
        $capabilities = $provider->capabilities();
        Assert::assertNotEmpty($capabilities->identityMethods, 'A driver supports at least one identity method.');

        foreach (Capabilities::INTERFACES as $feature => $interface) {
            Assert::assertSame(
                $capabilities->supports(Feature::from($feature)),
                $provider instanceof $interface,
                sprintf('Feature "%s" is declared %s but %s is %s.', $feature, $capabilities->supports(Feature::from($feature)) ? 'on' : 'off', $interface, $provider instanceof $interface ? 'implemented' : 'not implemented'),
            );
        }

        $package = json_decode((string) file_get_contents($composerJson), true);
        Assert::assertIsArray($package, "Cannot read {$composerJson}.");
        Assert::assertSame("hamada-emam-tech/esign-{$name}", $package['name'] ?? null, 'The package name follows hamada-emam-tech/esign-<name>.');
        Assert::assertSame($factory::class, $package['extra']['esign']['providers'][$name] ?? null, 'composer.json declares the factory under extra.esign.providers.');
        Assert::assertArrayHasKey('hamada-emam-tech/esign', $package['require'] ?? [], 'The driver requires the core.');

        return $provider;
    }
}
