<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class SessionController
{
    public function refresh(Request $request, Response $response): Response
    {
        return $response->withStatus(204);
    }
}
