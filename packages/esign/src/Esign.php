<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign;

use DateTimeInterface;
use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\EnvelopeValidator;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\CustomizesWebhookResponse;
use HamadaEmamTech\Esign\Contracts\Features\ExtendsExpiry;
use HamadaEmamTech\Esign\Contracts\Features\ManagesWebhooks;
use HamadaEmamTech\Esign\Contracts\Features\ReportsSigners;
use HamadaEmamTech\Esign\Contracts\Features\SendsReminders;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeDetails;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderNotConfigured;
use HamadaEmamTech\Esign\Exceptions\UnsupportedFeature;
use HamadaEmamTech\Esign\Webhook\WebhookResponse;
use HamadaEmamTech\Esign\Webhook\WebhookResult;

/**
 * Entry point, and the one workflow every provider follows:
 *
 *     $sent = $esign->send($envelope);              // checked against the provider's capabilities first
 *     $ref  = (string) $sent->ref();                // store this: "sadq:4f1c…"
 *
 *     $result = $esign->handleWebhook($headers, $rawBody, 'sadq');   // never throws for a bad call
 *     $status = $esign->status($ref);              // routed to the provider that holds the envelope
 *     if ($status === EnvelopeStatus::Signed) {
 *         $pdf = $esign->download($ref);
 *     }
 *
 * Your code only uses these methods and the value objects, so switching the default provider,
 * or running several at once, never changes it. Envelopes already out for signing keep going to
 * the provider in their reference.
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

    // ---- providers ---------------------------------------------------------------------

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

    public function capabilities(?string $provider = null): Capabilities
    {
        $driver = $this->provider($provider);

        return $driver instanceof HasCapabilities ? $driver->capabilities() : Capabilities::undeclared();
    }

    /** True only when the provider declares the feature and really implements it. */
    public function supports(Feature $feature, ?string $provider = null): bool
    {
        $interface = Capabilities::INTERFACES[$feature->value] ?? null;
        if ($interface !== null && ! $this->provider($provider) instanceof $interface) {
            return false;
        }

        return $this->capabilities($provider)->supports($feature);
    }

    // ---- the workflow ------------------------------------------------------------------------

    /**
     * Send an envelope. It is checked against the provider's capabilities first, the same way for
     * every provider, and nothing is sent if it doesn't fit.
     *
     * @throws Exceptions\EnvelopeNotSupported
     */
    public function send(Envelope $envelope, ?string $provider = null): SentEnvelope
    {
        $driver = $this->provider($provider);
        EnvelopeValidator::check($envelope, $this->capabilities($driver->name()), $driver->name());

        return $driver->send($envelope);
    }

    /** @param EnvelopeRef|SentEnvelope|string $envelope a reference, "provider:id", or a bare ID on the default provider */
    public function status(EnvelopeRef|SentEnvelope|string $envelope): EnvelopeStatus
    {
        [$driver, $id] = $this->locate($envelope);

        return $driver->status($id);
    }

    /** The envelope's status plus each signer's. */
    public function details(EnvelopeRef|SentEnvelope|string $envelope): EnvelopeDetails
    {
        [$driver, $id] = $this->locate($envelope);

        return $this->feature($driver, ReportsSigners::class, Feature::SignerDetails)->details($id);
    }

    /** The signed PDF. Only once status() is Signed. */
    public function download(EnvelopeRef|SentEnvelope|string $envelope): string
    {
        [$driver, $id] = $this->locate($envelope);

        return $driver->download($id);
    }

    /** Cancel an unfinished envelope. Some providers apply it asynchronously (Capabilities::$asyncCancel). */
    public function cancel(EnvelopeRef|SentEnvelope|string $envelope): void
    {
        [$driver, $id] = $this->locate($envelope);
        $driver->cancel($id);
    }

    /** @throws UnsupportedFeature when the provider can't send reminders */
    public function remind(EnvelopeRef|SentEnvelope|string $envelope, string $signerEmail): void
    {
        [$driver, $id] = $this->locate($envelope);
        $this->feature($driver, SendsReminders::class, Feature::Reminders)->remind($id, $signerEmail);
    }

    /** @throws UnsupportedFeature when the provider can't move deadlines */
    public function extendDeadline(EnvelopeRef|SentEnvelope|string $envelope, DateTimeInterface $until): void
    {
        [$driver, $id] = $this->locate($envelope);
        $this->feature($driver, ExtendsExpiry::class, Feature::ExpiryExtension)->extend($id, $until);
    }

    // ---- webhooks ------------------------------------------------------------------------------

    /**
     * Verify a webhook call and work out the answer to send. Never throws for a bad call:
     * check $result->isValid(), queue a status() check for $result->ref(), and always send
     * $result->response.
     *
     * @param array<string, string|string[]> $headers
     */
    public function handleWebhook(array $headers, string $rawBody, ?string $provider = null): WebhookResult
    {
        $driver = $this->provider($provider);
        $response = $driver instanceof CustomizesWebhookResponse ? $driver->webhookResponse($headers) : new WebhookResponse();

        try {
            return new WebhookResult($response, $driver->parseWebhook($headers, $rawBody));
        } catch (InvalidWebhook $e) {
            return new WebhookResult($response, null, $e);
        }
    }

    /**
     * The low-level version: throws InvalidWebhook instead of returning a result.
     *
     * @param array<string, string|string[]> $headers
     */
    public function parseWebhook(array $headers, string $rawBody, ?string $provider = null): WebhookEvent
    {
        return $this->provider($provider)->parseWebhook($headers, $rawBody);
    }

    /** @return string the provider's webhook ID */
    public function registerWebhook(string $url, #[\SensitiveParameter] ?string $secret = null, ?string $provider = null): string
    {
        return $this->feature($this->provider($provider), ManagesWebhooks::class, Feature::WebhookManagement)->registerWebhook($url, $secret);
    }

    public function deleteWebhook(string $webhookId, ?string $provider = null): void
    {
        $this->feature($this->provider($provider), ManagesWebhooks::class, Feature::WebhookManagement)->deleteWebhook($webhookId);
    }

    /** @return list<array{id: string, url: string}> */
    public function webhooks(?string $provider = null): array
    {
        return $this->feature($this->provider($provider), ManagesWebhooks::class, Feature::WebhookManagement)->webhooks();
    }

    /**
     * Which configured provider a webhook request belongs to, from the drivers that implement
     * RecognizesWebhooks. Null when none or more than one claims it. A hint, not a verification.
     *
     * @param array<string, string|string[]> $headers
     */
    public function detectWebhookProvider(array $headers, string $rawBody): ?string
    {
        $claims = [];
        foreach ($this->configured() as $name) {
            try {
                $driver = $this->provider($name);
            } catch (Exceptions\EsignException) {
                continue;   // misconfigured: it can't verify anything anyway
            }
            if ($driver instanceof Contracts\Features\RecognizesWebhooks && $driver->recognizesWebhook($headers, $rawBody)) {
                $claims[] = $name;
            }
        }

        return count($claims) === 1 ? $claims[0] : null;
    }

    /** @return list<string> providers that are installed and have a config block (plus "fake" when it is the default) */
    public function configured(): array
    {
        $names = array_map(fn ($n) => strtolower((string) $n), array_keys($this->config['providers'] ?? []));
        if (strtolower($this->config['default'] ?? '') === 'fake') {
            $names[] = 'fake';
        }

        return array_values(array_unique(array_filter($names, fn (string $n) => $this->registry->has($n))));
    }

    /** The full reference for an envelope: a bare ID belongs to the default provider. */
    public function ref(EnvelopeRef|SentEnvelope|string $envelope): EnvelopeRef
    {
        return match (true) {
            $envelope instanceof EnvelopeRef => $envelope,
            $envelope instanceof SentEnvelope => $envelope->ref(),
            str_contains($envelope, ':') => EnvelopeRef::parse($envelope),
            default => new EnvelopeRef($this->provider()->name(), $envelope),
        };
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @return array{0: SignatureProvider, 1: string} */
    private function locate(EnvelopeRef|SentEnvelope|string $envelope): array
    {
        if (is_string($envelope) && ! str_contains($envelope, ':')) {
            return [$this->provider(), $envelope];      // a bare ID: the default provider
        }
        $ref = $this->ref($envelope);

        return [$this->provider($ref->provider), $ref->envelopeId];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $interface
     *
     * @return T
     */
    private function feature(SignatureProvider $driver, string $interface, Feature $feature): object
    {
        if (! $driver instanceof $interface) {
            throw UnsupportedFeature::named($driver->name(), $feature);
        }

        return $driver;
    }
}
