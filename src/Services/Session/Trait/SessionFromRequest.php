<?php

declare(strict_types=1);

namespace App\Services\Session\Trait;

use App\Middleware\JwtSessionMiddleware;
use App\Services\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trait to retrieve session from request attributes.
 *
 * Usage: $session = $this->getSession($request);
 */
trait SessionFromRequest
{
    protected function getSession(Request $request): SessionInterface
    {
        $session = $request->getAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE);

        if ($session instanceof SessionInterface) {
            return $session;
        }

        throw new \RuntimeException('Session not found in request attributes. Ensure JwtSessionMiddleware is registered.');
    }
}
