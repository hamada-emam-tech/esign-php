<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox;

/**
 * The Dropbox Sign API key. It is both the HTTP Basic username for every API call and the
 * HMAC key that signs callback events, so it is kept out of var_dump() and error trackers.
 */
final readonly class DropboxCredentials
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
