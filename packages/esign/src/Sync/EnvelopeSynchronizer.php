<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sync;

use HamadaEmamTech\Esign\Contracts\EnvelopeListener;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Esign;

/**
 * The one sync routine for every provider. Run it from a queued job when a webhook arrives, or on a
 * schedule for providers without webhooks:
 *
 *     $result = $sync->sync($contract->esign_ref, EnvelopeStatus::tryFrom($contract->esign_status));
 *
 * It asks the provider for the real status, tells your listener when it changed, and downloads the
 * signed PDF once, when the envelope first reaches Signed. Pass the status you last stored as
 * $previous, so a retried webhook or a second run never downloads twice. If the download fails it
 * throws before the listener is called, so a queue can retry the job cleanly.
 */
final class EnvelopeSynchronizer
{
    public function __construct(
        private readonly Esign $esign,
        private readonly EnvelopeListener $listener,
    ) {
    }

    public function sync(EnvelopeRef|SentEnvelope|string $envelope, ?EnvelopeStatus $previous = null): SyncResult
    {
        $ref = $this->esign->ref($envelope);
        $status = $this->esign->status($ref);

        // Download before telling anyone: if it fails, the exception leaves nothing half-done and a
        // retried job starts clean, without the listener hearing about the same change twice.
        $pdf = $status === EnvelopeStatus::Signed && $previous !== EnvelopeStatus::Signed
            ? $this->esign->download($ref)
            : null;

        $changed = $status !== $previous;
        if ($changed) {
            $this->listener->statusChanged($ref, $status, $previous);
        }
        if ($pdf !== null) {
            $this->listener->signed($ref, $pdf);
        }

        return new SyncResult($ref, $status, $previous, $changed, $pdf !== null);
    }
}
