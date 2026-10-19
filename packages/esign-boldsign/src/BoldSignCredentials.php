<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign;

/** The BoldSign API key. Kept out of var_dump(), print_r() and error trackers. */
final readonly class BoldSignCredentials
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
    ) {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***'];
    }
}
