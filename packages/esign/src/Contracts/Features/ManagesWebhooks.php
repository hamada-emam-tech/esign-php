<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts\Features;

interface ManagesWebhooks
{
    /**
     * Register a URL the provider calls when envelopes change.
     *
     * @return string the provider's ID for this webhook
     */
    public function registerWebhook(string $url, #[\SensitiveParameter] ?string $secret = null): string;

    public function deleteWebhook(string $webhookId): void;

    /** @return list<array{id: string, url: string}> */
    public function webhooks(): array;
}
