<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Webhook;

/** The HTTP answer to send back for a webhook call. Copy it into your framework's response. */
final readonly class WebhookResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }
}
