<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use HamadaEmamTech\Esign\BoldSign\BoldSignCredentials;
use HamadaEmamTech\Esign\BoldSign\BoldSignFactory;
use HamadaEmamTech\Esign\BoldSign\BoldSignProvider;
use HamadaEmamTech\Esign\BoldSign\Tests\Support\FakeBoldSign;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Testing\AssertsProviderConformance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoldSignFactoryTest extends TestCase
{
    use AssertsProviderConformance;

    /** @return array<string, mixed> */
    private function config(): array
    {
        return ['api_key' => FakeBoldSign::API_KEY, 'webhook_secret' => FakeBoldSign::SECRET];
    }

    #[Test]
    public function it_builds_the_provider_and_finds_guzzle_on_its_own(): void
    {
        self::assertInstanceOf(BoldSignProvider::class, (new BoldSignFactory())->create($this->config()));
    }

    #[Test]
    public function it_conforms_to_the_shared_provider_contract(): void
    {
        $this->assertProviderConformance(new BoldSignFactory(), $this->config(), 'boldsign', __DIR__ . '/../../composer.json');
    }

    #[Test]
    public function the_api_key_is_required(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('BoldSign config is missing: api_key.');
        (new BoldSignFactory())->create(['api_key' => '  '] + $this->config());
    }

    /** @return iterable<string, array{string, string}> */
    public static function regions(): iterable
    {
        yield 'us' => ['us', 'https://api.boldsign.com'];
        yield 'eu' => ['eu', 'https://api-eu.boldsign.com'];
        yield 'ca' => ['ca', 'https://api-ca.boldsign.com'];
        yield 'au' => ['au', 'https://api-au.boldsign.com'];
        yield 'upper case' => ['EU', 'https://api-eu.boldsign.com'];
    }

    #[Test]
    #[DataProvider('regions')]
    public function each_region_calls_its_own_data_center(string $region, string $host): void
    {
        $fake = new FakeBoldSign();
        $factory = new HttpFactory();
        $provider = (new BoldSignFactory())->create(['region' => $region, 'http_client' => $fake, 'request_factory' => $factory] + $this->config());

        try {
            $provider->status('doc-1');
        } catch (EsignException) {
            // The fake answers 404 for an unknown document; only the URL matters here.
        }

        $headers = $fake->calls[0]['headers'];
        self::assertSame([FakeBoldSign::API_KEY], $headers['X-API-KEY']);
        self::assertSame(parse_url($host, PHP_URL_HOST), $fake->lastHost);
    }

    #[Test]
    public function the_region_defaults_to_us_and_base_url_overrides_it(): void
    {
        $fake = new FakeBoldSign();
        $factory = new HttpFactory();
        $build = fn (array $extra) => (new BoldSignFactory())->create($extra + ['http_client' => $fake, 'request_factory' => $factory] + $this->config());

        try {
            $build([])->status('doc-1');
        } catch (EsignException) {
        }
        self::assertSame('api.boldsign.com', $fake->lastHost);

        try {
            $build(['region' => 'eu', 'base_url' => 'https://boldsign.proxy.example/'])->status('doc-1');
        } catch (EsignException) {
        }
        self::assertSame('boldsign.proxy.example', $fake->lastHost);
    }

    #[Test]
    public function an_unknown_region_is_refused(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('us, eu, ca, au');
        (new BoldSignFactory())->create(['region' => 'mars'] + $this->config());
    }

    #[Test]
    public function a_plain_http_base_url_is_refused(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('https://');
        (new BoldSignFactory())->create(['base_url' => 'http://api.boldsign.com'] + $this->config());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badOptions(): iterable
    {
        yield 'zero tolerance' => [['webhook_tolerance' => 0], 'webhook_tolerance'];
        yield 'string tolerance' => [['webhook_tolerance' => '300'], 'webhook_tolerance'];
        yield 'zero expiry' => [['expiry_days' => 0], 'expiry_days'];
        yield 'negative scale' => [['coordinate_scale' => -1], 'coordinate_scale'];
        yield 'string scale' => [['coordinate_scale' => 'big'], 'coordinate_scale'];
    }

    /** @param array<string, mixed> $options */
    #[Test]
    #[DataProvider('badOptions')]
    public function invalid_options_are_refused(array $options, string $named): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage($named);
        (new BoldSignFactory())->create($options + $this->config());
    }

    #[Test]
    public function the_package_declares_itself_to_the_core_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $installedJson = sys_get_temp_dir() . '/esign-boldsign-installed-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installedJson, (string) json_encode(['packages' => [$composer]]));

        $registry = new ProviderRegistry($installedJson);
        $found = $registry->has('boldsign');   // discovery reads the file lazily, on first use
        unlink($installedJson);

        self::assertTrue($found);
        self::assertInstanceOf(BoldSignProvider::class, $registry->create('boldsign', $this->config()));
    }

    #[Test]
    public function the_api_key_stays_out_of_debug_output(): void
    {
        $dump = print_r(new BoldSignCredentials('api-key-value-secret'), true) . var_export((array) (new BoldSignCredentials('x'))->__debugInfo(), true);

        self::assertStringNotContainsString('api-key-value-secret', $dump);
    }
}
