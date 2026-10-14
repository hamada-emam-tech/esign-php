<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

/**
 * The webhook failed verification. Answer it with 200 anyway and log it:
 * a non-200 makes most providers retry, and a retry storm is worse than one dropped call.
 */
final class InvalidWebhook extends EsignException
{
}
