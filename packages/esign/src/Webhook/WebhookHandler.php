<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Webhook;

use Closure;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Sync\EnvelopeSynchronizer;
use Throwable;

/**
 * One webhook endpoint for every provider:
 *
 *     $handler = new WebhookHandler($esign, defer: fn (EnvelopeRef $ref) => dispatch(new SyncEnvelope((string) $ref)));
 *     $result  = $handler->handle($headers, $rawBody, $provider);   // $provider from the route, or null
 *     return response($result->response->body, $result->response->status, $result->response->headers);
 *
 * The steps are always the same: pick the driver (the one you name, or the one that recognizes
 * the request), verify the call with that driver, then hand the envelope reference to $defer (a
 * queue), or sync it at once with $sync. The webhook body is never trusted for the status.
 * handle() never throws for a bad or unknown call, so the provider always gets the answer it expects.
 */
final class WebhookHandler
{
    /**
     * @param (Closure(EnvelopeRef): void)|null $defer called with the envelope to refresh; queue it here
     */
    public function __construct(
        private readonly Esign $esign,
        private readonly ?Closure $defer = null,
        private readonly ?EnvelopeSynchronizer $sync = null,
    ) {
    }

    /**
     * @param array<string, string|string[]> $headers
     * @param string|null $provider the provider name from your route; null detects it
     */
    public function handle(array $headers, string $rawBody, ?string $provider = null): WebhookResult
    {
        if ($provider !== null && ! in_array(strtolower($provider), $this->esign->configured(), true)) {
            // The name usually comes from the URL, so it is untrusted: an unknown one is a 404, not a crash.
            return new WebhookResult(new WebhookResponse(404), null, new InvalidWebhook("Provider \"{$provider}\" is not configured for webhooks."));
        }
        $provider ??= $this->esign->detectWebhookProvider($headers, $rawBody);
        if ($provider === null) {
            // Nobody to verify it, so nobody to ask for a special answer: a plain 200 stops retries.
            return new WebhookResult(new WebhookResponse(), null, new InvalidWebhook('No configured provider recognizes this webhook. Name the provider in the route.'));
        }

        $result = $this->esign->handleWebhook($headers, $rawBody, $provider);
        $ref = $result->ref();
        if ($ref === null) {
            return new WebhookResult($result->response, null, $result->error, null, null, $provider);
        }

        if ($this->defer !== null) {
            ($this->defer)($ref);

            return new WebhookResult($result->response, $result->event, null, null, null, $provider);
        }

        if ($this->sync !== null) {
            try {
                return new WebhookResult($result->response, $result->event, null, $this->sync->sync($ref), null, $provider);
            } catch (Throwable $e) {
                return new WebhookResult($result->response, $result->event, null, null, $e, $provider);
            }
        }

        return new WebhookResult($result->response, $result->event, null, null, null, $provider);
    }
}
