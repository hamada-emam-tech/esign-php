<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Contracts;

use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;

/**
 * One e-signature provider (SADQ, Signit, emdha, DocuSign...).
 *
 * Every provider follows the same asynchronous lifecycle:
 * send an envelope → people sign outside your app → the provider calls your
 * webhook → you fetch the authoritative status → you download the sealed PDF.
 */
interface SignatureProvider
{
    /** The short name this provider is registered under, e.g. "sadq". */
    public function name(): string;

    /** Upload the documents, add the signers, and send the signing invitations. */
    public function send(Envelope $envelope): SentEnvelope;

    /** Ask the provider for the authoritative status. Never trust a webhook body instead. */
    public function status(string $envelopeId): EnvelopeStatus;

    /** The signed, sealed PDF. Call it only when status() is EnvelopeStatus::Signed. */
    public function download(string $envelopeId): string;

    /** Cancel an envelope that is not finished yet. */
    public function cancel(string $envelopeId): void;

    /**
     * Verify a webhook call and say which envelope it is about.
     *
     * @param array<string, string|string[]> $headers
     * @param string $rawBody the body exactly as received; signatures are computed over raw bytes
     *
     * @throws \HamadaEmamTech\Esign\Exceptions\InvalidWebhook when verification fails
     */
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent;
}
