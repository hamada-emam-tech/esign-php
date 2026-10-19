<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign;

/** Behaviour settings for the BoldSign driver. Everything except the API key. */
final readonly class BoldSignOptions
{
    public function __construct(
        /**
         * The signing secret of your BoldSign webhook ("Reveal" on the webhook page).
         * Without it every webhook is refused: BoldSign signs every delivery, so there is
         * no reason to accept an unsigned one.
         */
        #[\SensitiveParameter] public ?string $webhookSecret = null,
        /** How far, in seconds, the signature timestamp may be from now. BoldSign's own samples use 300. */
        public int $webhookTolerance = 300,
        /**
         * Signers with IdentityMethod::Email get BoldSign's "EmailOTP" authentication when true.
         * When false (the default) they sign through the emailed link alone (BoldSign's "None").
         */
        public bool $emailOtp = false,
        /** BoldSign's ExpiryDays. Null leaves BoldSign's default (60 days). */
        public ?int $expiryDays = null,
        /** The Message remind() sends with the reminder email. */
        public string $reminderMessage = 'A reminder to sign this document.',
        /** BoldSign requires a reason when a document is revoked; cancel() sends this one. */
        public string $revokeMessage = 'Cancelled by the sender.',
        /**
         * Multiplies every SignatureField coordinate before it is sent. BoldSign does not document
         * the unit of Bounds; 1.0 assumes PDF points from the top-left, like SignatureField.
         */
        public float $coordinateScale = 1.0,
    ) {
    }
}
