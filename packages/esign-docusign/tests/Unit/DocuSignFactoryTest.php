<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign\Tests\Unit;

use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\DocuSign\DocuSignCredentials;
use HamadaEmamTech\Esign\DocuSign\DocuSignFactory;
use HamadaEmamTech\Esign\DocuSign\DocuSignOptions;
use HamadaEmamTech\Esign\DocuSign\DocuSignProvider;
use HamadaEmamTech\Esign\DocuSign\Tests\Support\FakeDocuSign;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Testing\AssertsProviderConformance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignFactoryTest extends TestCase
{
    use AssertsProviderConformance;

    /** @return array<string, string> */
    private function config(): array
    {
        return ['integration_key' => 'ik', 'user_id' => 'user', 'private_key' => FakeDocuSign::privateKey(), 'webhook_secret' => 'hmac'];
    }

    #[Test]
    public function it_builds_the_provider_and_finds_guzzle_on_its_own(): void
    {
        $provider = (new DocuSignFactory())->create($this->config());

        self::assertInstanceOf(DocuSignProvider::class, $provider);
        self::assertSame('docusign', $provider->name());
    }

    #[Test]
    public function it_passes_the_core_conformance_checks(): void
    {
        $this->assertProviderConformance(new DocuSignFactory(), $this->config(), 'docusign', __DIR__ . '/../../composer.json');
        $this->assertProviderConformance(new DocuSignFactory(), ['phone_auth_workflow_id' => 'wf'] + $this->config(), 'docusign', __DIR__ . '/../../composer.json');
    }

    #[Test]
    public function missing_settings_are_all_named_at_once(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('DocuSign config is missing: user_id, private_key (or private_key_path).');
        (new DocuSignFactory())->create(['user_id' => '', 'private_key' => ''] + $this->config());
    }

    #[Test]
    public function the_private_key_can_come_from_a_file(): void
    {
        $path = sys_get_temp_dir() . '/esign-docusign-key-' . bin2hex(random_bytes(4)) . '.pem';
        file_put_contents($path, FakeDocuSign::privateKey());
        try {
            $config = ['private_key_path' => $path] + $this->config();
            unset($config['private_key']);
            self::assertInstanceOf(DocuSignProvider::class, (new DocuSignFactory())->create($config));
        } finally {
            unlink($path);
        }

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('private_key_path is not readable');
        (new DocuSignFactory())->create(['private_key' => '', 'private_key_path' => $path] + $this->config());
    }

    #[Test]
    public function a_broken_key_is_refused_at_boot_without_echoing_it(): void
    {
        try {
            (new DocuSignFactory())->create(['private_key' => "-----BEGIN RSA PRIVATE KEY-----\nnot-a-key\n-----END RSA PRIVATE KEY-----"] + $this->config());
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('not a PEM-encoded RSA private key', $e->getMessage());
            self::assertStringNotContainsString('not-a-key', $e->getMessage());
        }
    }

    #[Test]
    public function an_unknown_environment_is_refused(): void
    {
        foreach (['demo', 'sandbox', 'production'] as $ok) {
            (new DocuSignFactory())->create(['environment' => $ok] + $this->config());
        }

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('"demo" or "production"');
        (new DocuSignFactory())->create(['environment' => 'staging'] + $this->config());
    }

    #[Test]
    public function base_uri_needs_an_account_and_https(): void
    {
        try {
            (new DocuSignFactory())->create(['base_uri' => 'https://demo.docusign.net'] + $this->config());
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('needs account_id', $e->getMessage());
        }

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('https://');
        (new DocuSignFactory())->create(['base_uri' => 'http://demo.docusign.net', 'account_id' => 'acc'] + $this->config());
    }

    #[Test]
    public function webhook_secrets_accept_a_string_or_a_list(): void
    {
        $body = '{"event":"envelope-sent","data":{"envelopeId":"env-1"}}';
        $provider = (new DocuSignFactory())->create(['webhook_secret' => ['old', 'new']] + $this->config());
        $headers = ['X-DocuSign-Signature-1' => base64_encode(hash_hmac('sha256', $body, 'new', true))];

        self::assertSame('env-1', $provider->parseWebhook($headers, $body)->envelopeId);
    }

    #[Test]
    public function the_package_declares_itself_to_the_core_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $installedJson = sys_get_temp_dir() . '/esign-docusign-installed-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installedJson, (string) json_encode(['packages' => [$composer]]));

        $registry = new ProviderRegistry($installedJson);
        $found = $registry->has('docusign');   // discovery reads the file lazily, on first use
        unlink($installedJson);

        self::assertTrue($found);
        self::assertInstanceOf(DocuSignProvider::class, $registry->create('docusign', $this->config()));
    }

    #[Test]
    public function the_package_needs_no_dependency_beyond_the_psr_interfaces(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertSame(
            ['php', 'ext-json', 'ext-openssl', 'hamada-emam-tech/esign', 'psr/http-client', 'psr/http-factory', 'psr/simple-cache'],
            array_keys($composer['require']),
        );
    }

    #[Test]
    public function credentials_and_secrets_stay_out_of_debug_output(): void
    {
        $dump = print_r(new DocuSignCredentials('ik', 'user', FakeDocuSign::privateKey()), true)
            . print_r(new DocuSignOptions(['hmac-secret-value']), true);

        self::assertStringNotContainsString('PRIVATE KEY', $dump);
        self::assertStringNotContainsString('hmac-secret-value', $dump);
    }
}
