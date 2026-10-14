<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

use Throwable;

/** The provider answered with an error. Carries enough context to reproduce it, never credentials. */
final class ProviderError extends EsignException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", 0, $previous);
    }
}
