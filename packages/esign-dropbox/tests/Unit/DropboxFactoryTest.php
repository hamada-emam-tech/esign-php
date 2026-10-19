<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox\Tests\Unit;

use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Dropbox\DropboxCredentials;
use HamadaEmamTech\Esign\Dropbox\DropboxFactory;
use HamadaEmamTech\Esign\Dropbox\DropboxProvider;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Testing\AssertsProviderConformance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropboxFactoryTest extends TestCase
{
    use AssertsProviderConformance;

    #[Test]
    public function it_conforms_to_the_provider_contract(): void
    {
        $this->assertProviderConformance(new DropboxFactory(), ['api_key' => 'k'], 'dropbox', __DIR__ . '/../../composer.json');
    }

    #[Test]
    public function it_declares_what_dropbox_sign_supports(): void
    {
        $provider = (new DropboxFactory())->create(['api_key' => 'k']);
        self::assertInstanceOf(DropboxProvider::class, $provider);
        $capabilities = $provider->capabilities();

        self::assertSame([IdentityMethod::Email, IdentityMethod::SmsOtp], $capabilities->identityMethods);
        self::assertNull($capabilities->maxDocuments);
        self::assertTrue($capabilities->asyncCancel);
        foreach ([Feature::MultipleDocuments, Feature::SigningOrder, Feature::FieldPlacement, Feature::Reminders, Feature::SignerDetails] as $feature) {
            self::assertTrue($capabilities->supports($feature), $feature->value);
        }
        foreach ([Feature::SigningUrls, Feature::ExpiryExtension, Feature::WebhookManagement] as $feature) {
            self::assertFalse($capabilities->supports($feature), $feature->value);
        }
    }

    #[Test]
    public function it_builds_the_provider_and_finds_guzzle_on_its_own(): void
    {
        self::assertInstanceOf(DropboxProvider::class, (new DropboxFactory())->create(['api_key' => 'k']));
    }

    #[Test]
    public function the_api_key_is_required(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('Dropbox Sign config is missing: api_key.');
        (new DropboxFactory())->create(['api_key' => '']);
    }

    #[Test]
    public function test_mode_must_be_a_real_boolean(): void
    {
        (new DropboxFactory())->create(['api_key' => 'k', 'test_mode' => false]);

        // "false" as a string would be truthy somewhere down the line: refuse it.
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('test_mode must be true or false');
        (new DropboxFactory())->create(['api_key' => 'k', 'test_mode' => 'false']);
    }

    #[Test]
    public function client_id_and_base_url_are_validated(): void
    {
        (new DropboxFactory())->create(['api_key' => 'k', 'client_id' => 'app-1', 'base_url' => 'https://api.hellosign.com/v3/']);

        try {
            (new DropboxFactory())->create(['api_key' => 'k', 'client_id' => '']);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('client_id', $e->getMessage());
        }

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('https://');
        (new DropboxFactory())->create(['api_key' => 'k', 'base_url' => 'http://api.hellosign.com/v3']);
    }

    #[Test]
    public function the_package_declares_itself_to_the_core_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $installedJson = sys_get_temp_dir() . '/esign-dropbox-installed-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installedJson, (string) json_encode(['packages' => [$composer]]));

        $registry = new ProviderRegistry($installedJson);
        $found = $registry->has('dropbox');   // discovery reads the file lazily, on first use
        unlink($installedJson);

        self::assertTrue($found);
        self::assertInstanceOf(DropboxProvider::class, $registry->create('dropbox', ['api_key' => 'k']));
    }

    #[Test]
    public function the_api_key_stays_out_of_debug_output(): void
    {
        self::assertStringNotContainsString('api-key-value', print_r(new DropboxCredentials('api-key-value'), true));
    }
}
