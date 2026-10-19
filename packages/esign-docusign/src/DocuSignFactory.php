<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\DocuSign;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds the DocuSign provider from its config block:
 *
 *     'docusign' => [
 *         'environment'     => 'demo',            // or 'production'
 *         'integration_key' => '...',             // JWT "iss"
 *         'user_id'         => '...',             // JWT "sub": the impersonated user's GUID
 *         'private_key'     => '-----BEGIN RSA PRIVATE KEY-----...',   // or 'private_key_path'
 *         'webhook_secret'  => '...',             // Connect HMAC key (string or list)
 *         // optional: account_id, base_uri, phone_auth_workflow_id, void_reason,
 *         //           http_client, request_factory, stream_factory, cache (PSR-16)
 *     ]
 */
final class DocuSignFactory implements ProviderFactory
{
    /** The authentication service per environment (also the JWT "aud", which has no scheme). */
    public const AUTH_HOSTS = [
        'demo' => 'account-d.docusign.com',
        'production' => 'account.docusign.com',
    ];

    private const REQUIRED = ['integration_key', 'user_id'];

    public function create(array $config): SignatureProvider
    {
        $missing = array_values(array_filter(self::REQUIRED, fn (string $key) => ! self::filled($config[$key] ?? null)));
        if (! self::filled($config['private_key'] ?? null) && ! self::filled($config['private_key_path'] ?? null)) {
            $missing[] = 'private_key (or private_key_path)';
        }
        if ($missing !== []) {
            throw new EsignException('DocuSign config is missing: ' . implode(', ', $missing) . '.');
        }

        $environment = (string) ($config['environment'] ?? 'demo');
        $authHost = self::AUTH_HOSTS[$environment === 'sandbox' || $environment === 'developer' ? 'demo' : $environment]
            ?? throw new EsignException("DocuSign environment must be \"demo\" or \"production\", got \"{$environment}\".");

        $accountId = self::stringOrNull($config['account_id'] ?? null);
        $baseUri = self::stringOrNull($config['base_uri'] ?? null);
        if ($baseUri !== null && $accountId === null) {
            throw new EsignException('DocuSign base_uri needs account_id as well.');
        }
        if ($baseUri !== null && ! str_starts_with($baseUri, 'https://')) {
            throw new EsignException('DocuSign base_uri must start with https://, e.g. https://demo.docusign.net.');
        }

        [$client, $requests, $streams] = self::http($config);
        $cache = $config['cache'] ?? null;

        $credentials = new DocuSignCredentials(
            (string) $config['integration_key'],
            (string) $config['user_id'],
            self::privateKey($config),
            $accountId,
        );

        return new DocuSignProvider(
            new DocuSignApi($client, $requests, $streams, $credentials, $authHost, $baseUri, $cache instanceof CacheInterface ? $cache : null),
            new DocuSignOptions(
                webhookSecrets: self::secrets($config['webhook_secret'] ?? $config['webhook_secrets'] ?? []),
                voidReason: self::stringOrNull($config['void_reason'] ?? null) ?? 'Cancelled by the sender.',
                phoneAuthWorkflowId: self::stringOrNull($config['phone_auth_workflow_id'] ?? null),
            ),
        );
    }

    /**
     * The PEM key, read and checked now so a bad key fails at boot rather than on the first send.
     *
     * @param array<string, mixed> $config
     */
    private static function privateKey(array $config): string
    {
        $pem = $config['private_key'] ?? null;
        if (! self::filled($pem)) {
            $path = (string) $config['private_key_path'];
            $pem = is_readable($path) ? file_get_contents($path) : false;
            if ($pem === false) {
                throw new EsignException('DocuSign private_key_path is not readable.');
            }
        }
        $pem = (string) $pem;

        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        while (openssl_error_string() !== false) {
            // drain OpenSSL's error queue so it doesn't leak into later calls
        }
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            // Never echo the value: it is the private key.
            throw new EsignException('DocuSign private_key is not a PEM-encoded RSA private key.');
        }

        return $pem;
    }

    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient.
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

        // Optional packages are referenced by name, so the driver doesn't depend on them.
        $found = self::discovered('Http\Discovery\Psr18ClientDiscovery', 'find');
        if ($found !== null) {
            $client = $client instanceof ClientInterface ? $client : $found;
            $requests = $requests instanceof RequestFactoryInterface ? $requests : self::discovered('Http\Discovery\Psr17FactoryDiscovery', 'findRequestFactory');
            $streams = $streams instanceof StreamFactoryInterface ? $streams : self::discovered('Http\Discovery\Psr17FactoryDiscovery', 'findStreamFactory');
            if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
                return [$client, $requests, $streams];
            }
        }
        if (class_exists(\GuzzleHttp\Client::class) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();

            return [$client instanceof ClientInterface ? $client : new \GuzzleHttp\Client(['timeout' => 60]), $factory, $factory];
        }
        $symfony = 'Symfony\Component\HttpClient\Psr18Client';
        if (class_exists($symfony)) {
            $instance = new $symfony();
            if ($instance instanceof ClientInterface && $instance instanceof RequestFactoryInterface && $instance instanceof StreamFactoryInterface) {
                return [$client instanceof ClientInterface ? $client : $instance, $instance, $instance];
            }
        }

        throw new EsignException('DocuSign needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
    }

    private static function discovered(string $class, string $method): ?object
    {
        if (! class_exists($class)) {
            return null;
        }
        $find = [$class, $method];
        $found = is_callable($find) ? $find() : null;

        return is_object($found) ? $found : null;
    }

    /** @return list<string> */
    private static function secrets(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, fn ($s) => is_string($s) && $s !== ''));
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
