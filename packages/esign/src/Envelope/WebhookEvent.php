<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

/**
 * A verified webhook call. It tells you WHICH envelope changed, not what it changed to:
 * providers retry, reorder and sometimes send stale statuses, so always call
 * SignatureProvider::status() before acting on it.
 */
final readonly class WebhookEvent
{
    /** @param array<string, mixed> $payload the decoded body, for logging */
    public function __construct(
        public string $provider,
        public string $envelopeId,
        public ?string $eventId = null,
        public array $payload = [],
    ) {
    }
}
