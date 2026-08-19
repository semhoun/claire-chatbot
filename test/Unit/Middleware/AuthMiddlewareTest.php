<?php

declare(strict_types=1);

namespace App\Test\Unit\Middleware;

use App\Middleware\AuthMiddleware;
use App\Services\Auth;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\DispatcherInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use Slim\Views\Twig;

final class AuthMiddlewareTest extends TestCase
{
    public function testProtectedRouteReturnsUnauthorizedJsonForExpiredSession(): void
    {
        $route = $this->createStub(RouteInterface::class);
        $route->method('getName')->willReturn('auth.refresh');
        $routingResults = new RoutingResults(
            $this->createStub(DispatcherInterface::class),
            'GET',
            '/auth/refresh',
            RoutingResults::FOUND,
        );
        $session = new ArraySession();
        $session->start();
        $request = new ServerRequestFactory()
            ->createServerRequest('GET', 'https://claire.test/auth/refresh')
            ->withAttribute(RouteContext::ROUTE, $route)
            ->withAttribute(
                RouteContext::ROUTE_PARSER,
                $this->createStub(RouteParserInterface::class)
            )
            ->withAttribute(RouteContext::ROUTING_RESULTS, $routingResults)
            ->withAttribute('session', $session);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $auth = $this->createStub(Auth::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $middleware = new AuthMiddleware(
            $this->createStub(Twig::class),
            $auth,
            new Settings(['security' => ['public_routes' => ['/auth']]]),
        );

        $response = $middleware->process($request, $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"error":"unauthorized"}', (string) $response->getBody());
    }
}
