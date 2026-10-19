<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

/** How a signer proves who they are before signing. Providers reject methods they don't support. */
enum IdentityMethod: string
{
    case Email = 'email';
    case SmsOtp = 'sms_otp';
    case Nafath = 'nafath';
    /** Saudi Absher. */
    case Absher = 'absher';
    /** Saudi Wathq: checks the signer is the company's authorised signatory. */
    case Wathq = 'wathq';
    /** UAE PASS: the signer signs in their UAE PASS session. */
    case UaePass = 'uae_pass';
}
