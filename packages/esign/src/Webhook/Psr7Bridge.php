<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Webhook;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * For frameworks built on PSR-7 (Slim, Mezzio, Symfony with the PSR-7 bridge, Laravel via
 * psr-http-message-bridge). Needs psr/http-message and psr/http-factory, which the core only suggests.
 *
 *     return Psr7Bridge::handle($handler, $request, $responseFactory, $streamFactory, $args['provider'] ?? null);
 */
final class Psr7Bridge
{
    public static function handle(
        WebhookHandler $handler,
        ServerRequestInterface $request,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        ?string $provider = null,
    ): ResponseInterface {
        $body = (string) $request->getBody();
        // Providers that finish with a browser redirect (UAE PASS) send their data in the query string.
        if ($body === '' && $request->getUri()->getQuery() !== '') {
            $body = $request->getUri()->getQuery();
        }

        return self::toResponse($handler->handle($request->getHeaders(), $body, $provider)->response, $responses, $streams);
    }

    public static function toResponse(WebhookResponse $answer, ResponseFactoryInterface $responses, StreamFactoryInterface $streams): ResponseInterface
    {
        $response = $responses->createResponse($answer->status)->withBody($streams->createStream($answer->body));
        foreach ($answer->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
