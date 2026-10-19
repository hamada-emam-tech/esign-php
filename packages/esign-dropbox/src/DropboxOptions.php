<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox;

/** Behaviour settings for the Dropbox Sign driver. Everything except the API key. */
final readonly class DropboxOptions
{
    public function __construct(
        /**
         * Sends every request with test_mode=1: free, watermarked, and not legally binding.
         * On by default, the same way the SADQ driver defaults to its sandbox: going live is a
         * decision you make explicitly with 'test_mode' => false.
         */
        public bool $testMode = true,
        /**
         * The API app to send under. Dropbox Sign applies that app's branding and its callback URL.
         * Without it, events go to the account callback URL.
         */
        public ?string $clientId = null,
    ) {
    }
}
