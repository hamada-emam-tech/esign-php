<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts\Features;

/**
 * Lets one webhook endpoint serve every provider: the driver says whether a request looks like
 * one of its own webhooks. This is a cheap check on headers and body shape only. It picks which
 * driver verifies the call, and is never a verification itself.
 */
interface RecognizesWebhooks
{
    /** @param array<string, string|string[]> $headers */
    public function recognizesWebhook(array $headers, string $rawBody): bool;
}
