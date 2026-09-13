<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\SseAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;

final readonly class SseInternalController
{
    public function __construct(private SseAuthorization $authorizations)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpBadRequestException($request);
        }
        // Bound before decoding; the private API accepts credentials, not event/audio payloads.
        $raw = $request->getBody()->read(16385);
        try {
            $input = strlen($raw) > 16384 ? null : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpBadRequestException($request);
        }
        if (! is_array($input) || array_is_list($input)) {
            throw new HttpBadRequestException($request);
        }
        $path = $request->getUri()->getPath();
        if ($path === '/open') {
            $result = $this->authorizations->open($input);
        } else {
            if (count($input) !== 1 || ! is_string($input['authorization'] ?? null)) {
                throw new HttpBadRequestException($request);
            }
            if ($path === '/close') {
                $this->authorizations->close($input['authorization']);
                return $response->withStatus(204);
            }
            $result = $this->authorizations->snapshot($input['authorization']);
        }
        $response->getBody()->write(json_encode($result, JSON_THROW_ON_ERROR));
        return $response;
    }
}
