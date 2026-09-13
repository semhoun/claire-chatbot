<?php

declare(strict_types=1);

namespace App\Test\Unit\Middleware;

use App\Middleware\AuthMiddleware;
use App\Middleware\BaseUrlMiddleware;
use App\Services\Auth;
use App\Services\Session\ArraySession;
use App\Services\Settings;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Interfaces\DispatcherInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use App\Renderer\VueShell;

final class AuthMiddlewareTest extends TestCase
{
    #[TestWith(['', '/', 200])]
    #[TestWith(['/chat', '/', 200])]
    #[TestWith(['', '/manifest.webmanifest', 204])]
    #[TestWith(['/chat', '/manifest.webmanifest', 204])]
    #[TestWith(['', '/auth/refresh', 401])]
    #[TestWith(['/chat', '/auth/refresh', 401])]
    #[TestWith(['', '/auth/resource-token', 401])]
    #[TestWith(['/chat', '/auth/resource-token', 401])]
    public function testActualRoutingStackBeforeRouteRunner(string $basePath, string $path, int $status): void
    {
        $app = AppFactory::create();
        $app->setBasePath($basePath);
        $app->get($path, static fn (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface => $response->withStatus(204))->setName($path === '/' ? 'home' : 'test');
        $auth = $this->createStub(Auth::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $settings = new Settings([
            'name' => 'Claire',
            'security' => require dirname(__DIR__, 3) . '/config/settings/security.php',
        ]);
        $app->add(new AuthMiddleware(new VueShell(), $auth, $settings));
        $app->add(new BaseUrlMiddleware($app));
        $app->addRoutingMiddleware();
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://claire.test' . $basePath . $path)
            ->withHeader('Accept', 'text/html')
            ->withAttribute('session', new \App\Services\Session\InMemorySession([]));

        self::assertSame($status, $app->handle($request)->getStatusCode());
    }

    #[TestWith(['text/html', 200])]
    #[TestWith(['application/json', 401])]
    public function testHomeServesVueShellOrUnauthorizedJson(string $accept, int $status): void
    {
        $route = $this->createStub(RouteInterface::class);
        $route->method('getName')->willReturn('home');
        $request = new ServerRequestFactory()->createServerRequest('GET', '/')
            ->withAttribute('base_url', 'https://claire.test')
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
        $settings = new Settings(['name' => 'My <Chat> & "AI"', 'security' => ['public_routes' => []]]);
        $response = new AuthMiddleware(new VueShell(), $auth, $settings)
            ->process($request, $handler);
        self::assertSame($status, $response->getStatusCode());
        if ($status === 200) {
            self::assertStringContainsString('id="claire-vue-app"', (string) $response->getBody());
            self::assertStringContainsString(
                '<title>My &lt;Chat&gt; &amp; &quot;AI&quot;</title>',
                (string) $response->getBody()
            );
            self::assertStringNotContainsString('Se connecter', (string) $response->getBody());
        } else {
            self::assertSame(['error' => 'unauthorized'], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    #[TestWith(['/auth/refresh', 'GET'])]
    #[TestWith(['/auth/resource-token', 'POST'])]
    #[TestWith(['/chat/auth/refresh', 'GET', '/chat'])]
    #[TestWith(['/chat/auth/resource-token', 'POST', '/chat'])]
    public function testProtectedRouteReturnsUnauthorizedJsonForExpiredSession(
        string $path,
        string $method,
        string $basePath = '',
    ): void {
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
            ->withAttribute('base_url', 'https://claire.test' . $basePath)
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

    #[TestWith([''])]
    #[TestWith(['/chat'])]
    public function testManifestIsPublicBeforeAuthentication(string $basePath): void
    {
        $path = $basePath . '/manifest.webmanifest';
        $serverRequest = new ServerRequestFactory()->createServerRequest('GET', $path)
            ->withAttribute('base_url', 'https://claire.test' . $basePath)
            ->withAttribute(RouteContext::ROUTE, $this->createStub(RouteInterface::class))
            ->withAttribute(RouteContext::ROUTE_PARSER, $this->createStub(RouteParserInterface::class))
            ->withAttribute(RouteContext::ROUTING_RESULTS, new RoutingResults(
                $this->createStub(DispatcherInterface::class), 'GET', $path, RoutingResults::FOUND,
            ))
            ->withAttribute('session', new \App\Services\Session\InMemorySession([]));
        $response = new \Slim\Psr7\Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($serverRequest)->willReturn($response);
        $auth = $this->createMock(Auth::class);
        $auth->expects(self::never())->method('isAuthenticated');
        $settings = new Settings(['security' => require dirname(__DIR__, 3) . '/config/settings/security.php']);

        self::assertSame(
            $response,
            new AuthMiddleware(new VueShell(), $auth, $settings)->process($serverRequest, $handler)
        );
    }
}
