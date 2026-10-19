<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign;

/**
 * What JWT Grant needs: the integration key (the JWT "iss"), the GUID of the user to impersonate
 * (the JWT "sub"), and the integration's RSA private key that signs the JWT.
 * Kept out of var_dump() and error trackers.
 */
final readonly class DocuSignCredentials
{
    public function __construct(
        /** The integration key, also called client ID. */
        public string $integrationKey,
        /** The impersonated user's ID: a GUID, not an email. */
        public string $userId,
        /** PEM-encoded RSA private key of the integration's key pair. */
        #[\SensitiveParameter] public string $privateKey,
        /** The API account to work in. Null uses the user's default account from /oauth/userinfo. */
        public ?string $accountId = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return ['integrationKey' => $this->integrationKey, 'userId' => $this->userId, 'accountId' => $this->accountId, 'privateKey' => '***'];
    }
}
