<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Webhook;

use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Sync\SyncResult;
use Throwable;

/**
 * The outcome of Esign::handleWebhook(). Always send $response, even when $error is set:
 * a non-2xx answer makes most providers retry, and a retry storm is worse than one bad call.
 */
final readonly class WebhookResult
{
    public function __construct(
        public WebhookResponse $response,
        public ?WebhookEvent $event = null,
        public ?EsignException $error = null,
        /** Set when WebhookHandler synced the envelope right away. */
        public ?SyncResult $sync = null,
        /** Set when that sync failed. The webhook itself was valid; retry the sync later. */
        public ?Throwable $syncError = null,
        /** The provider that handled the call, given or detected. */
        public ?string $provider = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->event !== null;
    }

    /** The envelope to refresh, or null when the call was rejected. */
    public function ref(): ?EnvelopeRef
    {
        return $this->event === null ? null : new EnvelopeRef($this->event->provider, $this->event->envelopeId);
    }
}
