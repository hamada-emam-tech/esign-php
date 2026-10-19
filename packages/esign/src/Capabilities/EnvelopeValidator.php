<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Capabilities;

use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Exceptions\EnvelopeNotSupported;

/** Checks an envelope against a provider's capabilities, the same way for every provider. */
final class EnvelopeValidator
{
    /** @throws EnvelopeNotSupported listing every problem at once */
    public static function check(Envelope $envelope, Capabilities $capabilities, string $provider): void
    {
        $problems = [];

        if ($capabilities->maxDocuments !== null && count($envelope->documents) > $capabilities->maxDocuments) {
            $problems[] = sprintf('it takes at most %d document(s) per envelope; this one has %d. Merge the PDFs first.', $capabilities->maxDocuments, count($envelope->documents));
        }

        if ($capabilities->maxSigners !== null && count($envelope->signers) > $capabilities->maxSigners) {
            $problems[] = sprintf('it takes at most %d signer(s) per envelope; this one has %d.', $capabilities->maxSigners, count($envelope->signers));
        }

        $orders = [];
        foreach ($envelope->signers as $signer) {
            if (! $capabilities->allowsIdentity($signer->identity)) {
                $problems[] = sprintf('signer "%s" uses the "%s" identity method, which it does not support (supported: %s).', $signer->name, $signer->identity->value, implode(', ', array_map(fn ($m) => $m->value, $capabilities->identityMethods)));
            }
            if ($signer->fields !== [] && ! $capabilities->supports(Feature::FieldPlacement)) {
                $problems[] = sprintf('signer "%s" has signature fields, but it does not place fields.', $signer->name);
            }
            foreach ($signer->fields as $field) {
                if ($field->documentIndex >= count($envelope->documents)) {
                    $problems[] = sprintf('signer "%s" has a field on document %d, which does not exist.', $signer->name, $field->documentIndex);
                }
            }
            $orders[$signer->order] = true;
        }

        if (count($orders) > 1 && ! $capabilities->supports(Feature::SigningOrder)) {
            $problems[] = 'the signers have different orders, but it does not support a signing order.';
        }

        if ($problems !== []) {
            throw new EnvelopeNotSupported($provider, array_values(array_unique($problems)));
        }
    }
}
