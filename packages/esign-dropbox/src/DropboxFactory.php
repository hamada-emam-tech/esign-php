<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the Dropbox Sign provider from its config block:
 *
 *     'dropbox' => [
 *         'api_key'   => '...',                 // Settings → API in the Dropbox Sign dashboard
 *         'test_mode' => true,                  // the default; set false to send legally binding requests
 *         // optional: client_id (API app), base_url,
 *         //           http_client, request_factory, stream_factory
 *     ]
 */
final class DropboxFactory implements ProviderFactory
{
    public const BASE_URL = 'https://api.hellosign.com/v3';

    public function create(array $config): SignatureProvider
    {
        $apiKey = $config['api_key'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            throw new EsignException('Dropbox Sign config is missing: api_key.');
        }

        $testMode = $config['test_mode'] ?? true;
        if (! is_bool($testMode)) {
            throw new EsignException('Dropbox Sign test_mode must be true or false.');
        }

        $clientId = $config['client_id'] ?? null;
        if ($clientId !== null && (! is_string($clientId) || $clientId === '')) {
            throw new EsignException('Dropbox Sign client_id must be a non-empty string when set.');
        }

        $baseUrl = $config['base_url'] ?? self::BASE_URL;
        if (! is_string($baseUrl) || ! str_starts_with($baseUrl, 'https://')) {
            throw new EsignException('Dropbox Sign base_url must be an https:// URL.');
        }

        [$client, $requests, $streams] = self::http($config);

        return new DropboxProvider(
            new DropboxApi($client, $requests, $streams, new DropboxCredentials($apiKey), rtrim($baseUrl, '/')),
            new DropboxCredentials($apiKey),
            new DropboxOptions(testMode: $testMode, clientId: $clientId),
        );
    }

    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient. The same order as the SADQ driver.
     *
     * The optional libraries are referenced by name only, so static analysis passes without them.
     *
     * @param array<string, mixed> $config
     *
     * @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}
     */
    private static function http(array $config): array
    {
        $client = $config['http_client'] ?? null;
        $requests = $config['request_factory'] ?? null;
        $streams = $config['stream_factory'] ?? $requests;

        if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
            return [$client, $requests, $streams];
        }

        $found = self::discover();
        if ($found === null) {
            throw new EsignException('Dropbox Sign needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
        }

        return [
            $client instanceof ClientInterface ? $client : $found[0],
            $requests instanceof RequestFactoryInterface ? $requests : $found[1],
            $streams instanceof StreamFactoryInterface ? $streams : $found[2],
        ];
    }

    /** @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}|null */
    private static function discover(): ?array
    {
        // Each maker gets its class name as an argument, so nothing is resolved before class_exists().
        $candidates = [
            'Http\Discovery\Psr18ClientDiscovery' => static function (string $clients): array {
                $factories = 'Http\Discovery\Psr17FactoryDiscovery';

                return [$clients::find(), $factories::findRequestFactory(), $factories::findStreamFactory()];
            },
            'GuzzleHttp\Psr7\HttpFactory' => static function (string $factory): array {
                $client = 'GuzzleHttp\Client';
                if (! class_exists($client)) {
                    return [null, null, null];
                }
                $factory = new $factory();

                return [new $client(['timeout' => 60]), $factory, $factory];
            },
            'Symfony\Component\HttpClient\Psr18Client' => static function (string $class): array {
                $symfony = new $class();

                return [$symfony, $symfony, $symfony];
            },
        ];

        foreach ($candidates as $class => $make) {
            if (! class_exists($class)) {
                continue;
            }
            [$client, $requests, $streams] = $make($class);
            if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
                return [$client, $requests, $streams];
            }
        }

        return null;
    }
}
