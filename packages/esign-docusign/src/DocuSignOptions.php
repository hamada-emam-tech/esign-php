<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign;

/** Behaviour settings for the DocuSign driver. Everything except the credentials. */
final readonly class DocuSignOptions
{
    /** @var list<string> */
    public array $webhookSecrets;

    /**
     * @param list<string> $webhookSecrets
     */
    public function __construct(
        /**
         * The Connect HMAC keys of the account. DocuSign signs each Connect message with every key
         * and sends one X-DocuSign-Signature-N header per key, so several keys allow rotation.
         * Empty refuses every webhook: an unsigned Connect message cannot be told apart from a forged one.
         */
        #[\SensitiveParameter] array $webhookSecrets = [],
        /** The voidedReason sent with cancel(). DocuSign requires one when voiding. */
        public string $voidReason = 'Cancelled by the sender.',
        /**
         * The account's "Phone Authentication" identity verification workflow ID
         * (GET /identity_verification). Needed for IdentityMethod::SmsOtp; without it SMS signers are refused.
         */
        public ?string $phoneAuthWorkflowId = null,
    ) {
        $this->webhookSecrets = array_values(array_filter($webhookSecrets, fn (string $s) => $s !== ''));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['webhookSecrets' => $this->webhookSecrets === [] ? [] : '***', 'voidReason' => $this->voidReason, 'phoneAuthWorkflowId' => $this->phoneAuthWorkflowId];
    }
}
