<?php

declare(strict_types=1);

// In-memory admission/snapshot fixture, deliberately not a replacement for production auth tests.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Slim\Factory\AppFactory;

$secret = getenv('SSE_INTERNAL_SECRET');
if (! is_string($secret) || $secret === '') {
    throw new RuntimeException('Fixture requires SSE_INTERNAL_SECRET');
}
$authorizations = [];
$counts = ['open' => 0, 'snapshot' => 0, 'close' => 0];
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->map(['GET', 'POST'], '/{operation}', function (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args,
) use ($secret, &$authorizations, &$counts): ResponseInterface {
    $status = 200;
    $result = [];
    $operation = $args['operation'];
    $body = $request->getParsedBody() ?? [];
    if ($operation === 'health' && $request->getMethod() === 'GET') {
        $result = ['ready' => true];
    } elseif (! hash_equals($secret, $request->getHeaderLine('X-Claire-Sse-Secret'))) {
        $status = 403;
    } elseif ($operation === 'stats') {
        $result = [...$counts, 'authorizations' => count($authorizations)];
    } elseif ($request->getMethod() !== 'POST') {
        $status = 405;
    } elseif ($operation === 'open') {
        ++$counts['open'];
        $credential = $body['credential'] ?? '';
        $parts = is_string($credential) ? explode('.', $credential) : [];
        $claims = null;
        if (count($parts) === 2 && hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) {
            try {
                $claims = json_decode(base64_decode($parts[0], true) ?: '', true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $claims = null;
            }
        }
        if (! is_array($claims) || ($claims['expires'] ?? 0) <= time()) {
            $status = 401;
        } elseif (($body['path'] ?? '') !== '/brain/stream' || ($body['method'] ?? '') !== 'GET'
            || ! in_array($body['credentialType'] ?? '', ['capability', 'header'], true)
            || ($body['threadId'] ?? '') !== $claims['threadId']
            || ($body['sessionId'] ?? '') !== $claims['sessionId']) {
            $status = 403;
        } else {
            $authorization = bin2hex(random_bytes(32));
            $openedAt = microtime(true);
            $result = [
                'authorization' => $authorization,
                'userId' => $claims['userId'],
                'threadId' => $claims['threadId'],
                'sessionId' => $claims['sessionId'],
                'openedAt' => $openedAt,
                'deadline' => $openedAt + (int) getenv($claims['sessionId'] === 'deadline'
                    ? 'SSE_FIXTURE_SHORT_DURATION' : 'SSE_DURATION'),
                'remember' => $claims['remember'] ?? null,
            ];
            $authorizations[$authorization] = $result;
        }
    } elseif ($operation === 'snapshot') {
        ++$counts['snapshot'];
        $authorization = $authorizations[$body['authorization'] ?? ''] ?? null;
        if ($authorization === null || $authorization['deadline'] <= time()) {
            $status = 401;
        } else {
            $result = [
                'threadId' => $authorization['threadId'], 'sessionId' => $authorization['sessionId'],
                'messages' => [], 'responding' => true, 'activeMessageId' => 'load-message',
                'generationMessageId' => 'load-message', 'generationStatus' => 'running',
                'generation' => ['messageId' => 'load-message', 'status' => 'running'],
                'audioRequestIds' => [],
            ];
        }
    } elseif ($operation === 'close') {
        ++$counts['close'];
        unset($authorizations[$body['authorization'] ?? '']);
        return $response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    } else {
        $status = 404;
    }
    $response->getBody()->write(json_encode($result, JSON_THROW_ON_ERROR));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json')
        ->withHeader('Cache-Control', 'no-store');
});
$server = new HttpServer(static fn (ServerRequestInterface $request) => $app->handle($request));
$socket = new SocketServer('127.0.0.1:8082');
$server->listen($socket);
Loop::addSignal(SIGTERM, static function () use ($socket): void {
    $socket->close();
    Loop::stop();
});
fwrite(STDOUT, "SSE load fixture ready\n");
Loop::run();
