<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

use InvalidArgumentException;

final readonly class Signer
{
    /** @param list<SignatureField> $fields */
    public function __construct(
        public string $name,
        public string $email,
        public IdentityMethod $identity = IdentityMethod::Email,
        public ?string $nationalId = null,
        public ?string $mobile = null,
        public int $order = 1,
        public array $fields = [],
    ) {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Most providers invite by email; failing here beats failing half-way through a provider call.
            throw new InvalidArgumentException("Signer \"{$name}\" has an invalid email: {$email}");
        }
        if ($identity === IdentityMethod::Nafath && ! preg_match('/^[12]\d{9}$/', (string) $nationalId)) {
            throw new InvalidArgumentException("Signer \"{$name}\" needs a 10-digit national ID or Iqama number for Nafath.");
        }
    }

    /** @param list<SignatureField> $fields */
    public static function nafath(string $nationalId, string $name, string $email, int $order = 1, array $fields = []): self
    {
        return new self($name, $email, IdentityMethod::Nafath, $nationalId, null, $order, $fields);
    }

    /** @param list<SignatureField> $fields */
    public static function email(string $name, string $email, int $order = 1, array $fields = []): self
    {
        return new self($name, $email, IdentityMethod::Email, null, null, $order, $fields);
    }
}
