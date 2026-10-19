<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Adobe;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds the Acrobat Sign provider from its config block. Either a fixed token:
 *
 *     'adobe' => [
 *         'access_token'      => '...',                // an Integration Key or an OAuth access token
 *         'webhook_client_id' => '...',
 *     ]
 *
 * or OAuth refresh:
 *
 *     'adobe' => [
 *         'client_id' => '...', 'client_secret' => '...', 'refresh_token' => '...',
 *         'api_access_point' => 'https://api.na1.adobesign.com/',   // returned with the refresh token
 *     ]
 *
 * Optional: environment ('production' default | 'sandbox'), api_access_point (skips GET /baseUris),
 * integration_key (alias of access_token), webhook_client_id (defaults to client_id),
 * expires_in_days, page_height, http_client, request_factory, stream_factory, cache (PSR-16).
 */
final class AdobeFactory implements ProviderFactory
{
    /** The global hosts GET /api/rest/v6/baseUris is called on (Adobe's Embed 2.0 guide, "Base API Host"). */
    public const DISCOVERY_HOSTS = [
        'production' => 'https://api.adobesign.com',
        'sandbox' => 'https://api.adobesignsandbox.com',
    ];

    private const REFRESH = ['client_id', 'client_secret', 'refresh_token'];

    public function create(array $config): SignatureProvider
    {
        $token = self::stringOrNull($config['access_token'] ?? null) ?? self::stringOrNull($config['integration_key'] ?? null);
        $refresh = array_filter(self::REFRESH, fn (string $key) => self::stringOrNull($config[$key] ?? null) !== null);
        $accessPoint = self::stringOrNull($config['api_access_point'] ?? null);

        if ($refresh !== [] && count($refresh) !== count(self::REFRESH)) {
            throw new EsignException('Acrobat Sign OAuth refresh config is missing: ' . implode(', ', array_diff(self::REFRESH, $refresh)) . '.');
        }
        $canRefresh = $refresh !== [];
        if ($token === null && ! $canRefresh) {
            throw new EsignException('Acrobat Sign config needs access_token (or integration_key), or client_id, client_secret and refresh_token.');
        }
        if ($canRefresh && $accessPoint === null) {
            // The refresh endpoint lives on the account's access point, and finding that needs a token.
            throw new EsignException('Acrobat Sign OAuth refresh needs api_access_point: the api_access_point Adobe returned with the refresh token.');
        }
        if ($accessPoint !== null && ! preg_match('#^https://[^/\s]+/?$#', $accessPoint)) {
            throw new EsignException("Acrobat Sign api_access_point must look like https://api.na1.adobesign.com/, got \"{$accessPoint}\".");
        }

        $environment = (string) ($config['environment'] ?? 'production');
        $discoveryHost = self::DISCOVERY_HOSTS[$environment]
            ?? throw new EsignException("Acrobat Sign environment must be \"production\" or \"sandbox\", got \"{$environment}\".");

        [$client, $requests, $streams] = self::http($config);
        $cache = $config['cache'] ?? null;
        $clientId = self::stringOrNull($config['client_id'] ?? null);

        $credentials = new AdobeCredentials(
            $token,
            $clientId,
            self::stringOrNull($config['client_secret'] ?? null),
            self::stringOrNull($config['refresh_token'] ?? null),
        );

        $pageHeight = $config['page_height'] ?? null;
        $expires = $config['expires_in_days'] ?? null;

        return new AdobeProvider(
            new AdobeApi($client, $requests, $streams, $credentials, $discoveryHost, $accessPoint, $cache instanceof CacheInterface ? $cache : null),
            new AdobeOptions(
                webhookClientId: self::stringOrNull($config['webhook_client_id'] ?? null) ?? $clientId,
                expiresInDays: is_numeric($expires) ? max(1, (int) $expires) : null,
                pageHeight: is_numeric($pageHeight) && (float) $pageHeight > 0 ? (float) $pageHeight : null,
            ),
        );
    }

    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient. (The same detection as the SADQ driver.)
     * Optional classes are named by string so static analysis passes without them installed.
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

        $candidates = [];
        $discovery = 'Http\\Discovery\\Psr18ClientDiscovery';
        $factories = 'Http\\Discovery\\Psr17FactoryDiscovery';
        if (class_exists($discovery) && class_exists($factories)) {
            $candidates[] = [
                static fn () => self::callStatic($discovery, 'find'),
                static fn () => self::callStatic($factories, 'findRequestFactory'),
                static fn () => self::callStatic($factories, 'findStreamFactory'),
            ];
        }
        if (class_exists(\GuzzleHttp\Client::class) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();
            $candidates[] = [static fn () => new \GuzzleHttp\Client(['timeout' => 60]), static fn () => $factory, static fn () => $factory];
        }
        $symfony = 'Symfony\\Component\\HttpClient\\Psr18Client';
        if (class_exists($symfony)) {
            $instance = new $symfony();
            $candidates[] = [static fn () => $instance, static fn () => $instance, static fn () => $instance];
        }

        foreach ($candidates as [$makeClient, $makeRequests, $makeStreams]) {
            $c = $client instanceof ClientInterface ? $client : $makeClient();
            $r = $requests instanceof RequestFactoryInterface ? $requests : $makeRequests();
            $s = $streams instanceof StreamFactoryInterface ? $streams : $makeStreams();
            if ($c instanceof ClientInterface && $r instanceof RequestFactoryInterface && $s instanceof StreamFactoryInterface) {
                return [$c, $r, $s];
            }
        }

        throw new EsignException('Acrobat Sign needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
    }

    private static function callStatic(string $class, string $method): mixed
    {
        $callable = [$class, $method];

        return is_callable($callable) ? $callable() : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
