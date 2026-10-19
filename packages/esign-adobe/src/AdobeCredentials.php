<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe;

/**
 * How the driver authenticates to Acrobat Sign. Kept out of var_dump() and error trackers.
 *
 * Two modes, both sent as "Authorization: Bearer ...":
 * - a fixed token: an Integration Key, or an OAuth access token you refresh yourself;
 * - OAuth refresh: client_id + client_secret + refresh_token, exchanged at
 *   {api_access_point}/oauth/v2/refresh for short-lived access tokens. $accessToken, when also
 *   given, is used first and replaced on the first 401.
 */
final readonly class AdobeCredentials
{
    public function __construct(
        #[\SensitiveParameter] public ?string $accessToken = null,
        public ?string $clientId = null,
        #[\SensitiveParameter] public ?string $clientSecret = null,
        #[\SensitiveParameter] public ?string $refreshToken = null,
    ) {
    }

    public function canRefresh(): bool
    {
        return $this->clientId !== null && $this->clientSecret !== null && $this->refreshToken !== null;
    }

    /**
     * Every secret value, so error messages can be scrubbed of them.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->accessToken, $this->clientSecret, $this->refreshToken], fn (?string $s) => $s !== null && $s !== ''));
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'mode' => $this->canRefresh() ? 'oauth-refresh' : 'token', 'secrets' => '***'];
    }
}
