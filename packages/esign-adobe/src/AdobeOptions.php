<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe;

/** Behaviour settings for the Acrobat Sign driver. Everything except the credentials. */
final readonly class AdobeOptions
{
    public function __construct(
        /**
         * The client ID of the API application that registered your webhook. Acrobat Sign sends it in
         * X-AdobeSign-ClientId on every webhook call. Null refuses every webhook (fail closed).
         */
        public ?string $webhookClientId = null,
        /** Sets the agreement's expirationTime this many days ahead. Null leaves it to the account setting. */
        public ?int $expiresInDays = null,
        /**
         * Page height in PDF points, used to convert SignatureField's top-left y into Acrobat Sign's
         * bottom-left "top". Only needed when the driver cannot read one uniform page height from the PDF
         * (mixed page sizes, compressed page objects, non-PDF documents).
         */
        public ?float $pageHeight = null,
    ) {
    }
}
