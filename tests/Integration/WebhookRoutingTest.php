<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Monorepo\Tests\Integration;

use HamadaEmamTech\Esign\Adobe\AdobeFactory;
use HamadaEmamTech\Esign\BoldSign\BoldSignFactory;
use HamadaEmamTech\Esign\Contracts\Features\RecognizesWebhooks;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\DocuSign\DocuSignFactory;
use HamadaEmamTech\Esign\DocuSign\Tests\Support\FakeDocuSign;
use HamadaEmamTech\Esign\Dropbox\DropboxFactory;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Sadq\SadqFactory;
use HamadaEmamTech\Esign\Sadq\SadqOptions;
use HamadaEmamTech\Esign\Sadq\Tests\Support\FakeSadq;
use HamadaEmamTech\Esign\Signit\SignitFactory;
use HamadaEmamTech\Esign\Signit\Tests\Support\FakeSignit;
use HamadaEmamTech\Esign\Sync\CallbackListener;
use HamadaEmamTech\Esign\Sync\EnvelopeSynchronizer;
use HamadaEmamTech\Esign\UaePass\UaePassFactory;
use HamadaEmamTech\Esign\Webhook\WebhookHandler;
use HamadaEmamTech\Esign\Zoho\ZohoFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One endpoint for every provider: each provider's webhook shape (from its documentation) must be
 * claimed by exactly its own driver, so detection can never send a call to the wrong verifier.
 */
final class WebhookRoutingTest extends TestCase
{
    /** @return array<string, array{0: class-string<\HamadaEmamTech\Esign\Contracts\ProviderFactory>, 1: array<string, mixed>}> */
    private static function drivers(): array
    {
        return [
            'sadq' => [SadqFactory::class, ['account_id' => 'a', 'account_secret' => 'b', 'username' => 'u@example.sa', 'password' => 'p', 'client_secret' => 'c']],
            'docusign' => [DocuSignFactory::class, ['integration_key' => 'ik', 'user_id' => 'user', 'private_key' => FakeDocuSign::privateKey(), 'webhook_secret' => 'hmac']],
            'zoho' => [ZohoFactory::class, ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh']],
            'dropbox' => [DropboxFactory::class, ['api_key' => 'k']],
            'boldsign' => [BoldSignFactory::class, ['api_key' => 'k', 'webhook_secret' => 's']],
            'adobe' => [AdobeFactory::class, ['access_token' => 'token', 'webhook_client_id' => 'client']],
            'signit' => [SignitFactory::class, ['client_id' => 'c', 'client_secret' => 's', 'webhook_secret' => FakeSignit::WEBHOOK_KEY]],
            'uaepass' => [UaePassFactory::class, ['client_id' => 'c', 'client_secret' => 's', 'finish_callback_url' => 'https://app.example.ae/done']],
        ];
    }

    /** @return iterable<string, array{0: string, 1: array<string, string|string[]>, 2: string}> */
    public static function webhooks(): iterable
    {
        yield 'sadq' => ['sadq', ['Content-Type' => 'application/json'], '{"RequestId":"6f1c2a90-0000-4000-8000-000000000001","Status":3}'];
        yield 'docusign' => ['docusign', ['X-DocuSign-Signature-1' => 'aGVsbG8=', 'Content-Type' => 'application/json'], '{"event":"envelope-completed","apiVersion":"v2.1","data":{"accountId":"a","envelopeId":"e"}}'];
        yield 'zoho' => ['zoho', ['X-ZS-WEBHOOK-SIGNATURE' => 'aGVsbG8='], '{"notifications":{"operation_type":"RequestCompleted"},"requests":{"request_id":"1"}}'];
        yield 'dropbox (form)' => ['dropbox', ['Content-Type' => 'application/x-www-form-urlencoded'], 'json=' . rawurlencode('{"event":{"event_time":"1","event_type":"signature_request_all_signed","event_hash":"ab"},"signature_request":{"signature_request_id":"r"}}')];
        yield 'dropbox (json)' => ['dropbox', ['Content-Type' => 'application/json'], '{"event":{"event_time":"1","event_type":"callback_test","event_hash":"ab"}}'];
        yield 'boldsign' => ['boldsign', ['X-BoldSign-Signature' => 't=1, s0=ab', 'X-BoldSign-Event' => 'Completed'], '{"event":{"id":"1","eventType":"Completed"},"data":{"documentId":"d"}}'];
        yield 'adobe' => ['adobe', ['X-AdobeSign-ClientId' => 'client'], '{"event":"AGREEMENT_WORKFLOW_COMPLETED","agreement":{"id":"a"}}'];
        yield 'signit (json)' => ['signit', ['Content-Type' => 'application/json'], FakeSignit::encryptedWebhook(['event_type' => 'REQUEST_COMPLETED', 'data' => ['signature_request_id' => 1]])];
        yield 'signit (form)' => ['signit', ['Content-Type' => 'application/x-www-form-urlencoded'], FakeSignit::encryptedWebhook(['data' => ['signature_request_id' => 1]], asForm: true)];
        yield 'uaepass' => ['uaepass', [], 'status=finished&signer_process_id=sp_c4eed0a1f478f72454803695d53c4c52'];
        // Header names arrive in every case, depending on the framework.
        yield 'docusign, lower-case header' => ['docusign', ['x-docusign-signature-1' => ['aGVsbG8=']], '{"event":"envelope-sent","data":{"envelopeId":"e"}}'];
        yield 'adobe, upper-case header' => ['adobe', ['X-ADOBESIGN-CLIENTID' => 'client'], '{"event":"AGREEMENT_CREATED","agreement":{"id":"a"}}'];
    }

    private static function esign(): Esign
    {
        $providers = [];
        foreach (self::drivers() as $name => [, $config]) {
            $providers[$name] = $config;
        }
        $esign = new Esign(['default' => 'sadq', 'providers' => $providers], new ProviderRegistry('/nonexistent'));
        foreach (self::drivers() as $name => [$factory]) {
            $esign->extend($name, $factory);
        }

        return $esign;
    }

    /** @param array<string, string|string[]> $headers */
    #[Test]
    #[DataProvider('webhooks')]
    public function each_webhook_is_claimed_by_its_own_driver_only(string $expected, array $headers, string $body): void
    {
        $esign = self::esign();
        $claims = [];
        foreach (array_keys(self::drivers()) as $name) {
            $driver = $esign->provider($name);
            self::assertInstanceOf(RecognizesWebhooks::class, $driver, "{$name} must recognize its webhooks");
            if ($driver->recognizesWebhook($headers, $body)) {
                $claims[] = $name;
            }
        }

        self::assertSame([$expected], $claims);
        self::assertSame($expected, $esign->detectWebhookProvider($headers, $body));
    }

    #[Test]
    public function noise_is_claimed_by_nobody(): void
    {
        $esign = self::esign();

        foreach ([[[], ''], [['User-Agent' => 'scanner'], '<html>'], [['Content-Type' => 'application/json'], '{"hello":"world"}'], [[], 'a=b&c=d']] as [$headers, $body]) {
            self::assertNull($esign->detectWebhookProvider($headers, $body));
        }
    }

    #[Test]
    public function one_endpoint_takes_a_real_sadq_webhook_all_the_way_to_the_signed_pdf(): void
    {
        $sadq = new FakeSadq();
        $esign = new Esign(['default' => 'sadq', 'providers' => ['sadq' => [], 'adobe' => self::drivers()['adobe'][1]]], new ProviderRegistry('/nonexistent'));
        $esign->extend('sadq', fn () => $sadq->provider(new SadqOptions(webhookId: 'wh-1', accountEmail: FakeSadq::OWNER)))->extend('adobe', AdobeFactory::class);
        $ref = (string) $esign->send(new Envelope([new Document('contract.pdf', FakeSadq::ORIGINAL)], [Signer::email('Sara', 'sara@example.sa')]))->ref();
        $sadq->signAll(EnvelopeRef::parse($ref)->envelopeId);

        $stored = [];
        $handler = new WebhookHandler($esign, sync: new EnvelopeSynchronizer($esign, new CallbackListener(
            signed: function (EnvelopeRef $r, string $pdf) use (&$stored) {
                $stored[(string) $r] = $pdf;
            },
        )));

        // The default driver checks no token, so the body alone identifies SADQ.
        $result = $handler->handle(['Content-Type' => 'application/json'], (string) json_encode(['RequestId' => EnvelopeRef::parse($ref)->envelopeId, 'Status' => 3]));

        self::assertSame('sadq', $result->provider);
        self::assertSame(EnvelopeStatus::Signed, $result->sync?->status);
        self::assertSame([$ref => FakeSadq::SIGNED], $stored);
        self::assertSame(200, $result->response->status);
    }
}
