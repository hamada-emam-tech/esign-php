<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Sync\CallbackListener;
use HamadaEmamTech\Esign\Sync\EnvelopeSynchronizer;
use HamadaEmamTech\Esign\Testing\FakeProvider;
use HamadaEmamTech\Esign\Webhook\Psr7Bridge;
use HamadaEmamTech\Esign\Webhook\WebhookHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookFlowTest extends TestCase
{
    private FakeProvider $fake;

    private Esign $esign;

    /** @var list<string> what the listener heard, in order */
    private array $heard = [];

    protected function setUp(): void
    {
        $this->fake = new FakeProvider('secret', 'fake');
        $fake = $this->fake;
        $this->esign = (new Esign(['default' => 'fake', 'providers' => ['fake' => []]], new ProviderRegistry('/nonexistent')))
            ->extend('fake', fn () => $fake);
    }

    private function listener(): CallbackListener
    {
        return new CallbackListener(
            statusChanged: function (EnvelopeRef $ref, EnvelopeStatus $status, ?EnvelopeStatus $previous) {
                $this->heard[] = "status:{$ref}:" . ($previous?->value ?? 'none') . '->' . $status->value;
            },
            signed: function (EnvelopeRef $ref, string $pdf) {
                $this->heard[] = "signed:{$ref}:" . (str_starts_with($pdf, '%PDF') ? 'pdf' : 'not-pdf');
            },
        );
    }

    private function send(): string
    {
        return (string) $this->esign->send(new Envelope([new Document('a.pdf', '%PDF a')], [Signer::email('Sara', 'sara@example.sa')]))->ref();
    }

    // ---- synchronizer --------------------------------------------------------------------

    #[Test]
    public function sync_tells_the_listener_only_about_changes_and_downloads_once(): void
    {
        $sync = new EnvelopeSynchronizer($this->esign, $this->listener());
        $ref = $this->send();

        $first = $sync->sync($ref);                                  // nothing stored yet
        $same = $sync->sync($ref, EnvelopeStatus::Sent);             // unchanged
        $this->fake->sign(EnvelopeRef::parse($ref)->envelopeId);
        $signed = $sync->sync($ref, EnvelopeStatus::Sent);
        $again = $sync->sync($ref, EnvelopeStatus::Signed);          // a retried webhook

        self::assertTrue($first->changed);
        self::assertFalse($same->changed);
        self::assertTrue($signed->changed && $signed->downloaded);
        self::assertFalse($again->changed || $again->downloaded);
        self::assertSame(["status:{$ref}:none->sent", "status:{$ref}:sent->signed", "signed:{$ref}:pdf"], $this->heard);
    }

    #[Test]
    public function a_failed_download_tells_nobody_so_a_retry_starts_clean(): void
    {
        $ref = $this->send();
        $this->fake->sign(EnvelopeRef::parse($ref)->envelopeId);
        $this->fake->failDownloads = true;

        try {
            (new EnvelopeSynchronizer($this->esign, $this->listener()))->sync($ref, EnvelopeStatus::Sent);
            self::fail('Expected the download error');
        } catch (EsignException) {
        }
        self::assertSame([], $this->heard);
    }

    // ---- handler -------------------------------------------------------------------------

    #[Test]
    public function the_handler_detects_the_provider_verifies_and_defers(): void
    {
        $ref = $this->send();
        [$headers, $body] = $this->fake->webhookFor(EnvelopeRef::parse($ref)->envelopeId);
        $queued = [];
        $handler = new WebhookHandler($this->esign, defer: function (EnvelopeRef $r) use (&$queued) {
            $queued[] = (string) $r;
        });

        $result = $handler->handle($headers, $body);      // no provider given: detected

        self::assertSame('fake', $result->provider);
        self::assertTrue($result->isValid());
        self::assertSame([$ref], $queued);
        self::assertSame(200, $result->response->status);
    }

    #[Test]
    public function the_handler_can_sync_at_once(): void
    {
        $ref = $this->send();
        $this->fake->sign(EnvelopeRef::parse($ref)->envelopeId);
        [$headers, $body] = $this->fake->webhookFor(EnvelopeRef::parse($ref)->envelopeId);

        $result = (new WebhookHandler($this->esign, sync: new EnvelopeSynchronizer($this->esign, $this->listener())))->handle($headers, $body, 'fake');

        self::assertSame(EnvelopeStatus::Signed, $result->sync?->status);
        self::assertTrue($result->sync->downloaded);
        self::assertContains("signed:{$ref}:pdf", $this->heard);
    }

    #[Test]
    public function a_forged_call_is_refused_with_a_200_and_never_reaches_the_queue(): void
    {
        $queued = 0;
        $handler = new WebhookHandler($this->esign, defer: function () use (&$queued) {
            ++$queued;
        });

        $result = $handler->handle(['X-Fake-Signature' => 'forged'], '{"envelope_id":"fake-1"}', 'fake');

        self::assertFalse($result->isValid());
        self::assertInstanceOf(InvalidWebhook::class, $result->error);
        self::assertSame(200, $result->response->status);
        self::assertSame(0, $queued);
    }

    #[Test]
    public function an_unknown_provider_in_the_url_is_a_404_not_a_crash(): void
    {
        $result = (new WebhookHandler($this->esign))->handle([], '{}', '../../etc/passwd');

        self::assertSame(404, $result->response->status);
        self::assertInstanceOf(InvalidWebhook::class, $result->error);
    }

    #[Test]
    public function a_call_nobody_recognizes_gets_a_plain_200(): void
    {
        $result = (new WebhookHandler($this->esign))->handle(['User-Agent' => 'scanner'], 'hello');

        self::assertNull($result->provider);
        self::assertSame(200, $result->response->status);
        self::assertStringContainsString('No configured provider recognizes', (string) $result->error?->getMessage());
    }

    #[Test]
    public function a_sync_failure_is_reported_but_the_provider_still_gets_its_200(): void
    {
        $ref = $this->send();
        [$headers, $body] = $this->fake->webhookFor(EnvelopeRef::parse($ref)->envelopeId);
        $exploding = new CallbackListener(statusChanged: function () {
            throw new \RuntimeException('database down');
        });

        $result = (new WebhookHandler($this->esign, sync: new EnvelopeSynchronizer($this->esign, $exploding)))->handle($headers, $body, 'fake');

        self::assertTrue($result->isValid());
        self::assertSame('database down', $result->syncError?->getMessage());
        self::assertSame(200, $result->response->status);
    }

    #[Test]
    public function the_psr7_bridge_reads_the_body_or_the_redirect_query_and_writes_the_answer(): void
    {
        $ref = $this->send();
        [$headers, $body] = $this->fake->webhookFor(EnvelopeRef::parse($ref)->envelopeId);
        $queued = [];
        $handler = new WebhookHandler($this->esign, defer: function (EnvelopeRef $r) use (&$queued) {
            $queued[] = (string) $r;
        });
        $factory = new HttpFactory();

        $response = Psr7Bridge::handle($handler, new ServerRequest('POST', '/esign/webhooks/fake', $headers, $body), $factory, $factory, 'fake');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$ref], $queued);
    }
}
