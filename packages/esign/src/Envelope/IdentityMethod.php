<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

/** How a signer proves who they are before signing. Providers reject methods they don't support. */
enum IdentityMethod: string
{
    case Email = 'email';
    case SmsOtp = 'sms_otp';
    case Nafath = 'nafath';
}
