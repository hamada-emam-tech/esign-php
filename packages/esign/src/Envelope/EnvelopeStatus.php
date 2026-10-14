<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

/** One status vocabulary for every provider. Each driver maps its provider's statuses onto these. */
enum EnvelopeStatus: string
{
    case Sent = 'sent';
    case PartiallySigned = 'partially_signed';
    case Signed = 'signed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** No more changes will happen; stop polling. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Sent, self::PartiallySigned => false,
            default => true,
        };
    }
}
