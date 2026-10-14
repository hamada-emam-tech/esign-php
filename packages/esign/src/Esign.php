<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\ProviderNotConfigured;

/**
 * Entry point. Holds the config, builds providers on first use, and forwards
 * calls to the default provider.
 *
 *     $esign = new Esign([
 *         'default'   => 'sadq',
 *         'providers' => ['sadq' => [...], 'signit' => [...]],
 *     ]);
 *     $esign->send($envelope);                       // default provider
 *     $esign->provider('signit')->send($envelope);   // any installed provider
 */
final class Esign
{
    /** @var array<string, SignatureProvider> */
    private array $built = [];

    private readonly ProviderRegistry $registry;

    /** @param array{default?: string, providers?: array<string, array<string, mixed>>} $config */
    public function __construct(private readonly array $config = [], ?ProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new ProviderRegistry();
    }

    /** The default provider, or the one you name. Built once, then reused. */
    public function provider(?string $name = null): SignatureProvider
    {
        $name = strtolower($name ?? $this->config['default'] ?? throw ProviderNotConfigured::noDefault());

        if (! isset($this->built[$name])) {
            if (! $this->registry->has($name)) {
                throw Exceptions\ProviderNotInstalled::named($name);
            }
            $providerConfig = $this->config['providers'][$name] ?? ($name === 'fake' ? [] : throw ProviderNotConfigured::named($name));
            $this->built[$name] = $this->registry->create($name, $providerConfig);
        }

        return $this->built[$name];
    }

    /**
     * Register a provider by hand, e.g. your own in-house driver.
     *
     * @param class-string<ProviderFactory>|ProviderFactory|callable(array<string, mixed>): SignatureProvider $factory
     */
    public function extend(string $name, string|ProviderFactory|callable $factory): self
    {
        $this->registry->register($name, $factory);
        unset($this->built[strtolower($name)]);

        return $this;
    }

    /** @return list<string> every provider that is installed or registered */
    public function installed(): array
    {
        return $this->registry->names();
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        return $this->provider()->send($envelope);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        return $this->provider()->status($envelopeId);
    }

    public function download(string $envelopeId): string
    {
        return $this->provider()->download($envelopeId);
    }

    public function cancel(string $envelopeId): void
    {
        $this->provider()->cancel($envelopeId);
    }

    /** @param array<string, string|string[]> $headers */
    public function parseWebhook(array $headers, string $rawBody, ?string $provider = null): WebhookEvent
    {
        return $this->provider($provider)->parseWebhook($headers, $rawBody);
    }
}
