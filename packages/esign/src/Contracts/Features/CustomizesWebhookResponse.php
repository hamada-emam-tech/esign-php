<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts\Features;

use HamadaEmamTech\Esign\Webhook\WebhookResponse;

/**
 * For providers that expect a specific answer to a webhook (a fixed body, an echoed header).
 * Providers without it get a plain 200.
 */
interface CustomizesWebhookResponse
{
    /** @param array<string, string|string[]> $headers the request headers */
    public function webhookResponse(array $headers): WebhookResponse;
}
