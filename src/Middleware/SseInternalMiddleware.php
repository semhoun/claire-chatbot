<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;

final readonly class SseInternalMiddleware implements MiddlewareInterface
{
    private string $secret;

    public function __construct(Settings $settings)
    {
        $secret = $settings->get('sse.secret');
        if (! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('SSE internal secret is required.');
        }
        $this->secret = $secret;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $server = $request->getServerParams();
        if (! self::isPrivateListener($server)
            || ! hash_equals($this->secret, $request->getHeaderLine('X-Claire-Sse-Secret'))) {
            $response = new Response(403);
        } elseif ($request->getUri()->getQuery() !== '') {
            $response = new Response(400);
        } elseif (! in_array($request->getUri()->getPath(), ['/open', '/snapshot', '/close'], true)) {
            $response = new Response(404);
        } elseif ($request->getMethod() !== 'POST') {
            $response = new Response(405);
        } else {
            try {
                $response = $handler->handle($request);
            } catch (HttpException $exception) {
                $response = new Response($exception->getCode());
            } catch (\Throwable) {
                // Never expose or log credentials, request bodies, or backend exception details.
                $response = new Response(503);
            }
        }
        return $response->withoutHeader('X-Claire-Token')->withoutHeader('X-Claire-Minitoken')
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $server */
    public static function isPrivateListener(array $server): bool
    {
        return ($server['SERVER_ADDR'] ?? null) === '127.0.0.1'
            && (string) ($server['SERVER_PORT'] ?? '') === '8082'
            && ($server['REMOTE_ADDR'] ?? null) === '127.0.0.1';
    }
}
