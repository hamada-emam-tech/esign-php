<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the BoldSign provider from its config block:
 *
 *     'boldsign' => [
 *         'api_key'        => '...',
 *         'region'         => 'us',                 // 'eu', 'ca', 'au', or set 'base_url'
 *         'webhook_secret' => '...',                // required to accept webhooks
 *         // optional: webhook_tolerance (seconds, default 300), email_otp (bool),
 *         //           expiry_days, reminder_message, revoke_message, coordinate_scale,
 *         //           http_client, request_factory, stream_factory
 *     ]
 */
final class BoldSignFactory implements ProviderFactory
{
    /** https://developers.boldsign.com/sdks/php-sdk/ ("region-specific API base URLs") */
    public const BASE_URLS = [
        'us' => 'https://api.boldsign.com',
        'eu' => 'https://api-eu.boldsign.com',
        'ca' => 'https://api-ca.boldsign.com',
        'au' => 'https://api-au.boldsign.com',
    ];

    public function create(array $config): SignatureProvider
    {
        $apiKey = $config['api_key'] ?? null;
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new EsignException('BoldSign config is missing: api_key.');
        }

        $region = strtolower((string) ($config['region'] ?? 'us'));
        $baseUrl = self::stringOrNull($config['base_url'] ?? null) ?? self::BASE_URLS[$region]
            ?? throw new EsignException("BoldSign region must be one of: " . implode(', ', array_keys(self::BASE_URLS)) . ", got \"{$region}\".");
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new EsignException('BoldSign base_url must start with https://, so the API key never travels in clear text.');
        }

        $tolerance = $config['webhook_tolerance'] ?? 300;
        if (! is_int($tolerance) || $tolerance < 1) {
            throw new EsignException('BoldSign webhook_tolerance must be a whole number of seconds, 1 or more.');
        }
        $expiryDays = $config['expiry_days'] ?? null;
        if ($expiryDays !== null && (! is_int($expiryDays) || $expiryDays < 1)) {
            throw new EsignException('BoldSign expiry_days must be a whole number of days, 1 or more.');
        }
        $scale = $config['coordinate_scale'] ?? 1.0;
        if ((! is_int($scale) && ! is_float($scale)) || $scale <= 0) {
            throw new EsignException('BoldSign coordinate_scale must be a number above 0.');
        }

        [$client, $requests, $streams] = self::http($config);

        return new BoldSignProvider(
            new BoldSignApi($client, $requests, $streams, new BoldSignCredentials($apiKey), rtrim($baseUrl, '/')),
            new BoldSignOptions(
                webhookSecret: self::stringOrNull($config['webhook_secret'] ?? null),
                webhookTolerance: $tolerance,
                emailOtp: (bool) ($config['email_otp'] ?? false),
                expiryDays: $expiryDays,
                reminderMessage: self::stringOrNull($config['reminder_message'] ?? null) ?? 'A reminder to sign this document.',
                revokeMessage: self::stringOrNull($config['revoke_message'] ?? null) ?? 'Cancelled by the sender.',
                coordinateScale: (float) $scale,
            ),
        );
    }

    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient. The optional ones are looked up by name, so
     * none of them is a dependency.
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

        $found = self::discovered() ?? self::guzzle() ?? self::symfony();
        if ($found === null) {
            throw new EsignException('BoldSign needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
        }

        return [
            $client instanceof ClientInterface ? $client : $found[0],
            $requests instanceof RequestFactoryInterface ? $requests : $found[1],
            $streams instanceof StreamFactoryInterface ? $streams : $found[2],
        ];
    }

    /** @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}|null */
    private static function discovered(): ?array
    {
        $find = ['Http\\Discovery\\Psr18ClientDiscovery', 'find'];
        $findRequests = ['Http\\Discovery\\Psr17FactoryDiscovery', 'findRequestFactory'];
        $findStreams = ['Http\\Discovery\\Psr17FactoryDiscovery', 'findStreamFactory'];
        if (! is_callable($find) || ! is_callable($findRequests) || ! is_callable($findStreams)) {
            return null;
        }
        [$client, $requests, $streams] = [$find(), $findRequests(), $findStreams()];

        return $client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface
            ? [$client, $requests, $streams]
            : null;
    }

    /** @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}|null */
    private static function guzzle(): ?array
    {
        if (! class_exists(\GuzzleHttp\Client::class) || ! class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            return null;
        }
        $factory = new \GuzzleHttp\Psr7\HttpFactory();

        return [new \GuzzleHttp\Client(['timeout' => 60]), $factory, $factory];
    }

    /** @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}|null */
    private static function symfony(): ?array
    {
        $class = 'Symfony\\Component\\HttpClient\\Psr18Client';
        if (! class_exists($class)) {
            return null;
        }
        $symfony = new $class();

        return $symfony instanceof ClientInterface && $symfony instanceof RequestFactoryInterface && $symfony instanceof StreamFactoryInterface
            ? [$symfony, $symfony, $symfony]
            : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
