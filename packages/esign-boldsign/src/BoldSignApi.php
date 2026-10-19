<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\BoldSign;

use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Talks to BoldSign's REST API v1 with an API key in the X-API-KEY header.
 *
 * Unlike some providers, BoldSign uses HTTP status codes for errors (400, 401, 402, 403, 404,
 * 417, 422, 429, 500: https://developers.boldsign.com/troubleshooting/possible-errors-and-solution/).
 * The shape of an error body is not documented, so the message is read from the usual
 * ASP.NET keys when present, and a non-JSON body is never echoed.
 *
 * @internal used by BoldSignProvider
 */
final class BoldSignApi
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly BoldSignCredentials $credentials,
        private readonly string $baseUrl,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->json($this->call('GET', $path, $query), "GET {$path}");
    }

    /**
     * @param array<string, string> $query
     * @param array<mixed> $body
     *
     * @return array<mixed>|null null when BoldSign answers with no content (revoke answers 204)
     */
    public function post(string $path, array $query, array $body): ?array
    {
        return $this->write('POST', $path, $query, $body);
    }

    /**
     * @param array<string, string> $query
     * @param array<mixed> $body
     *
     * @return array<mixed>|null null when BoldSign answers with no content
     */
    public function patch(string $path, array $query, array $body): ?array
    {
        return $this->write('PATCH', $path, $query, $body);
    }

    /**
     * @param array<string, string> $query
     * @param array<mixed> $body
     *
     * @return array<mixed>|null
     */
    private function write(string $method, string $path, array $query, array $body): ?array
    {
        $response = $this->call($method, $path, $query, $body);
        if ($response->getStatusCode() === 204 || trim((string) $response->getBody()) === '') {
            return null;
        }

        return $this->json($response, "{$method} {$path}");
    }

    /**
     * The raw response body, for endpoints that return a file.
     *
     * @param array<string, string> $query
     */
    public function bytes(string $path, array $query = []): string
    {
        return (string) $this->call('GET', $path, $query, null, 'application/pdf, application/json')->getBody();
    }

    /**
     * @param array<string, string> $query
     * @param array<mixed>|null $body
     */
    private function call(string $method, string $path, array $query, ?array $body = null, string $accept = 'application/json'): ResponseInterface
    {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $request = $this->requests->createRequest($method, $url)
            ->withHeader('X-API-KEY', $this->credentials->apiKey)
            ->withHeader('Accept', $accept);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        }

        $what = "{$method} {$path}";
        $response = $this->send($request, $what);
        if ($response->getStatusCode() >= 400) {
            throw $this->error($response, $what);
        }

        return $response;
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $response, string $what): array
    {
        $json = json_decode((string) $response->getBody(), true);
        if (! is_array($json)) {
            throw new ProviderError('boldsign', "{$what} returned a non-JSON response.", $response->getStatusCode());
        }

        return $json;
    }

    private function error(ResponseInterface $response, string $what): ProviderError
    {
        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);
        $message = is_array($json) ? $this->messageFrom($json) : null;
        $code = is_array($json) && (is_string($json['code'] ?? null) || is_int($json['code'] ?? null)) ? (string) $json['code'] : null;

        $message ??= match ($status) {
            401 => 'Unauthorized. Check the API key, and that "region" matches the data center your BoldSign account lives in.',
            402 => 'Payment required. The BoldSign account has a billing problem.',
            403 => 'Forbidden. This API key cannot access that resource.',
            404 => 'Not found.',
            429 => 'Rate limit reached (2000 requests per hour live, 50 in sandbox). Try again later.',
            default => $status >= 500 ? 'BoldSign is unavailable. Try again later.' : "HTTP {$status}",
        };

        // The key only ever travels in a header, but never let it into a message, whatever BoldSign echoes.
        $message = str_replace($this->credentials->apiKey, '***', $message);

        return new ProviderError('boldsign', "{$what}: {$message}", $status, $code);
    }

    /** @param array<mixed> $json */
    private function messageFrom(array $json): ?string
    {
        $parts = [];
        foreach (['error', 'message', 'title', 'detail'] as $key) {
            if (is_string($json[$key] ?? null) && $json[$key] !== '' && ! in_array($json[$key], $parts, true)) {
                $parts[] = $json[$key];
            }
        }
        // ASP.NET validation problems: {"errors": {"Field": ["reason", ...]}}
        if (is_array($json['errors'] ?? null)) {
            foreach ($json['errors'] as $field => $reasons) {
                $reasons = implode(' ', array_filter((array) $reasons, 'is_string'));
                if ($reasons !== '') {
                    $parts[] = is_string($field) ? "{$field}: {$reasons}" : $reasons;
                }
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function send(RequestInterface $request, string $what): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $reason = str_replace($this->credentials->apiKey, '***', $e->getMessage());

            throw new ProviderError('boldsign', "{$what}: could not reach BoldSign ({$reason})", null, null, $e);
        }
    }
}
