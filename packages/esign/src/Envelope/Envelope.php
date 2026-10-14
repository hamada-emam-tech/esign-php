<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

use InvalidArgumentException;

/** What you send for signing: documents, the people who sign, and how to reach you back. */
final readonly class Envelope
{
    /**
     * @param list<Document> $documents
     * @param list<Signer> $signers
     * @param array<string, string> $metadata your own references (contract id, tenant id), echoed where the provider allows
     */
    public function __construct(
        public array $documents,
        public array $signers,
        public string $subject = 'Please sign this document',
        public string $message = '',
        public ?string $callbackUrl = null,
        public array $metadata = [],
    ) {
        if ($documents === []) {
            throw new InvalidArgumentException('An envelope needs at least one document.');
        }
        if ($signers === []) {
            throw new InvalidArgumentException('An envelope needs at least one signer.');
        }
    }
}
