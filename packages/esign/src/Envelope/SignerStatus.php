<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

enum SignerStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';
}
