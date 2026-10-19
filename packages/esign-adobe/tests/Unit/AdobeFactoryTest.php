<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use HamadaEmamTech\Esign\Adobe\AdobeCredentials;
use HamadaEmamTech\Esign\Adobe\AdobeFactory;
use HamadaEmamTech\Esign\Adobe\AdobeProvider;
use HamadaEmamTech\Esign\Adobe\Tests\Support\FakeAdobe;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Testing\AssertsProviderConformance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdobeFactoryTest extends TestCase
{
    use AssertsProviderConformance;

    /** @return array<string, string> */
    private function config(): array
    {
        return ['access_token' => 'token', 'webhook_client_id' => 'client'];
    }

    /** @return array<string, string> */
    private function refreshConfig(): array
    {
        return ['client_id' => 'cid', 'client_secret' => 'secret', 'refresh_token' => 'refresh', 'api_access_point' => 'https://api.na1.adobesign.com/'];
    }

    #[Test]
    public function it_builds_the_provider_and_finds_guzzle_on_its_own(): void
    {
        self::assertInstanceOf(AdobeProvider::class, (new AdobeFactory())->create($this->config()));
        self::assertInstanceOf(AdobeProvider::class, (new AdobeFactory())->create(['integration_key' => 'key']));
        self::assertInstanceOf(AdobeProvider::class, (new AdobeFactory())->create($this->refreshConfig()));
    }

    #[Test]
    public function it_conforms_to_the_shared_provider_contract(): void
    {
        $this->assertProviderConformance(new AdobeFactory(), $this->config(), 'adobe', __DIR__ . '/../../composer.json');
    }

    #[Test]
    public function without_any_credential_it_says_what_is_needed(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('needs access_token (or integration_key), or client_id, client_secret and refresh_token');
        (new AdobeFactory())->create([]);
    }

    #[Test]
    public function a_partial_refresh_config_names_what_is_missing(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('missing: client_secret, refresh_token.');
        (new AdobeFactory())->create(['client_id' => 'cid', 'access_token' => 't']);
    }

    #[Test]
    public function oauth_refresh_needs_the_api_access_point(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('needs api_access_point');
        (new AdobeFactory())->create(['api_access_point' => ''] + $this->refreshConfig());
    }

    #[Test]
    public function a_malformed_access_point_or_environment_is_refused(): void
    {
        try {
            (new AdobeFactory())->create(['api_access_point' => 'http://api.na1.adobesign.com'] + $this->config());
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('api_access_point must look like', $e->getMessage());
        }

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('"production" or "sandbox"');
        (new AdobeFactory())->create(['environment' => 'staging'] + $this->config());
    }

    #[Test]
    public function the_sandbox_discovers_on_the_sandbox_host(): void
    {
        $fake = new FakeAdobe();
        $factory = new HttpFactory();
        $provider = (new AdobeFactory())->create(['environment' => 'sandbox', 'access_token' => FakeAdobe::TOKEN, 'http_client' => $fake, 'request_factory' => $factory, 'stream_factory' => $factory]);

        try {
            $provider->status('x');
        } catch (\Throwable) {
        }
        self::assertSame('https://api.adobesignsandbox.com/api/rest/v6/baseUris', $fake->calls[0]['url']);
    }

    #[Test]
    public function the_webhook_client_id_defaults_to_the_oauth_client_id(): void
    {
        $provider = (new AdobeFactory())->create($this->refreshConfig());

        self::assertSame('X1', $provider->parseWebhook(['X-AdobeSign-ClientId' => 'cid'], '{"agreement":{"id":"X1"}}')->envelopeId);
    }

    #[Test]
    public function the_package_declares_itself_to_the_core_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $installedJson = sys_get_temp_dir() . '/esign-adobe-installed-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installedJson, (string) json_encode(['packages' => [$composer]]));

        $registry = new ProviderRegistry($installedJson);
        $found = $registry->has('adobe');   // discovery reads the file lazily, on first use
        unlink($installedJson);

        self::assertTrue($found);
        self::assertInstanceOf(AdobeProvider::class, $registry->create('adobe', $this->config()));
    }

    #[Test]
    public function credentials_stay_out_of_debug_output(): void
    {
        $dump = print_r(new AdobeCredentials('access-token-value', 'cid', 'client-secret-value', 'refresh-token-value'), true);

        foreach (['access-token-value', 'client-secret-value', 'refresh-token-value'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
    }
}
