<?php

declare(strict_types=1);

namespace App\Test\Unit\Middleware;

use App\Middleware\AuthMiddleware;
use App\Services\Auth;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\DispatcherInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use App\Renderer\VueShell;

final class AuthMiddlewareTest extends TestCase
{
    #[TestWith(['text/html', 200])]
    #[TestWith(['application/json', 401])]
    public function testHomeServesVueShellOrUnauthorizedJson(string $accept, int $status): void
    {
        $route = $this->createStub(RouteInterface::class);
        $route->method('getName')->willReturn('home');
        $request = new ServerRequestFactory()->createServerRequest('GET', '/')
            ->withHeader('Accept', $accept)->withAttribute(RouteContext::ROUTE, $route)
            ->withAttribute(RouteContext::ROUTE_PARSER, $this->createStub(RouteParserInterface::class))
            ->withAttribute(RouteContext::ROUTING_RESULTS, new RoutingResults(
                $this->createStub(DispatcherInterface::class), 'GET', '/', RoutingResults::FOUND,
            ))
            ->withAttribute('session', new \App\Services\Session\InMemorySession([]));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $auth = $this->createStub(Auth::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $response = new AuthMiddleware(new VueShell(), $auth, new Settings(['security' => ['public_routes' => []]]))
            ->process($request, $handler);
        self::assertSame($status, $response->getStatusCode());
        if ($status === 200) {
            self::assertStringContainsString('id="claire-vue-app"', (string) $response->getBody());
            self::assertStringNotContainsString('Se connecter', (string) $response->getBody());
        } else {
            self::assertSame(['error' => 'unauthorized'], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    #[TestWith(['/auth/refresh', 'GET'])]
    #[TestWith(['/auth/resource-token', 'POST'])]
    public function testProtectedRouteReturnsUnauthorizedJsonForExpiredSession(string $path, string $method): void
    {
        $route = $this->createStub(RouteInterface::class);
        $route->method('getName')->willReturn('auth.refresh');
        $routingResults = new RoutingResults(
            $this->createStub(DispatcherInterface::class),
            $method,
            $path,
            RoutingResults::FOUND,
        );
        $session = new ArraySession();
        $session->start();
        $request = new ServerRequestFactory()
            ->createServerRequest($method, 'https://claire.test' . $path)
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
            new VueShell(),
            $auth,
            new Settings(['security' => ['public_routes' => ['/auth']]]),
        );

        $response = $middleware->process($request, $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"error":"unauthorized"}', (string) $response->getBody());
    }
}
