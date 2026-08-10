<?php

declare(strict_types=1);

namespace App\Renderer;

use Psr\Http\Message\ResponseInterface;

final class JsonRenderer
{
    public function json(
        ResponseInterface $response,
        mixed $data = [],
        int $statusCode = 200,
    ): ResponseInterface {
        $response = $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);

        $response->getBody()->write(
            (string) json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            )
        );

        return $response;
    }
}
