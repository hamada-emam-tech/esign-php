<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Tests\Unit;

use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Testing\FakeProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    #[Test]
    public function nafath_signers_need_a_valid_national_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Signer::nafath('12345', 'Ahmed', 'ahmed@example.sa');
    }

    #[Test]
    public function signers_need_a_valid_email_before_any_provider_call(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Signer::email('Ahmed', 'not-an-email');
    }

    #[Test]
    public function an_envelope_needs_documents_and_signers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Envelope([new Document('a.pdf', 'x')], []);
    }

    #[Test]
    public function a_tampered_webhook_is_rejected(): void
    {
        $fake = new FakeProvider();
        [$headers, $body] = $fake->webhookFor('fake-1');

        $this->expectException(InvalidWebhook::class);
        $fake->parseWebhook($headers, $body . ' ');
    }
}
