<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Dropbox;

use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Talks to the Dropbox Sign API v3: HTTP Basic auth with the API key as the username and an
 * empty password, multipart/form-data bodies (JSON where only JSON is documented), and the error envelope every endpoint uses:
 *
 *     {"error": {"error_msg": "...", "error_name": "bad_request", "error_path": "..."}}
 *
 * Unlike SADQ, Dropbox Sign reports failures with a 4xx/5xx HTTP status, so the status decides.
 * The multipart body is built here, so no HTTP library beyond PSR-18/PSR-17 is needed.
 *
 * @internal used by DropboxProvider
 */
final class DropboxApi
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly DropboxCredentials $credentials,
        private readonly string $baseUrl,
    ) {
    }

    /** @return array<mixed> the decoded JSON body */
    public function get(string $path): array
    {
        return $this->json($this->call('GET', $path), "GET {$path}");
    }

    /**
     * POST a multipart/form-data body.
     *
     * @param list<array{name: string, contents: string, filename?: string, contentType?: string}> $parts
     *
     * @return array<mixed> the decoded JSON body; [] when the body is empty, as on cancel
     */
    public function post(string $path, array $parts = []): array
    {
        $boundary = 'esign-' . bin2hex(random_bytes(16));
        $request = $this->request('POST', $path)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withBody($this->streams->createStream(self::multipart($parts, $boundary)));

        return $this->json($this->send($request, "POST {$path}"), "POST {$path}");
    }

    /**
     * POST a JSON body, for endpoints documented as application/json only (remind).
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    public function postJson(string $path, array $body): array
    {
        $request = $this->request('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));

        return $this->json($this->send($request, "POST {$path}"), "POST {$path}");
    }

    /** GET a binary download, such as a PDF. */
    public function download(string $path): string
    {
        return (string) $this->call('GET', $path)->getBody();
    }

    private function call(string $method, string $path): ResponseInterface
    {
        return $this->send($this->request($method, $path), "{$method} {$path}");
    }

    private function request(string $method, string $path): RequestInterface
    {
        return $this->requests->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->credentials->apiKey . ':'))
            ->withHeader('Accept', 'application/json');
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $response, string $what): array
    {
        $body = (string) $response->getBody();
        if (trim($body) === '') {
            return [];
        }
        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new ProviderError('dropbox', "{$what} returned a non-JSON response.", $response->getStatusCode());
        }

        return $json;
    }

    /** Sends the request and turns every 4xx/5xx into a ProviderError. */
    private function send(RequestInterface $request, string $what): ResponseInterface
    {
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderError('dropbox', "{$what}: could not reach Dropbox Sign ({$this->scrub($e->getMessage())})", null, null, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }

        $json = json_decode((string) $response->getBody(), true);
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $name = is_string($error['error_name'] ?? null) && $error['error_name'] !== '' ? $error['error_name'] : null;
        $message = is_string($error['error_msg'] ?? null) && $error['error_msg'] !== '' ? $error['error_msg'] : "HTTP {$status}";
        if (is_string($error['error_path'] ?? null) && $error['error_path'] !== '') {
            $message .= " ({$error['error_path']})";
        }
        if ($status === 401) {
            $message = 'Authentication failed. Check api_key. ' . $message;
        }

        throw new ProviderError('dropbox', "{$what}: {$this->scrub($message)}", $status, $name);
    }

    /** Never let the API key reach a message, even if something echoes it back. */
    private function scrub(string $message): string
    {
        return $this->credentials->apiKey === '' ? $message : str_replace($this->credentials->apiKey, '***', $message);
    }

    /** @param list<array{name: string, contents: string, filename?: string, contentType?: string}> $parts */
    private static function multipart(array $parts, string $boundary): string
    {
        $body = '';
        foreach ($parts as $part) {
            $disposition = 'form-data; name="' . self::quote($part['name']) . '"';
            if (isset($part['filename'])) {
                $disposition .= '; filename="' . self::quote($part['filename']) . '"';
            }
            $body .= "--{$boundary}\r\nContent-Disposition: {$disposition}\r\n";
            if (isset($part['contentType'])) {
                $body .= "Content-Type: {$part['contentType']}\r\n";
            }
            $body .= "\r\n{$part['contents']}\r\n";
        }

        return $body . "--{$boundary}--\r\n";
    }

    /** Header-safe quoting for names and filenames: no quotes, backslashes or line breaks. */
    private static function quote(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    }
}
