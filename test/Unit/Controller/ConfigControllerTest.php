<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Brain\BrainRegistry;
use App\Controller\ConfigController;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

#[AllowMockObjectsWithoutExpectations]
final class ConfigControllerTest extends TestCase
{
    private SessionInterface $session;

    private EntityManagerInterface $entityManager;

    private EntityRepository $userRepository;

    private ConfigController $controller;

    private ResponseFactory $responseFactory;

    private User $user;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(EntityRepository::class);
        $this->responseFactory = new ResponseFactory();

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn('user-123');

        $this->entityManager->method('getRepository')->willReturnCallback(function (string $class) {
            if ($class === User::class) {
                return $this->userRepository;
            }

            return null;
        });

        $settings = new Settings([
            'llm' => ['brains' => []],
            'tools' => ['comfyui' => ['enabled' => false]],
        ]);

        $container = $this->createMock(ContainerInterface::class);

        $this->controller = new ConfigController(
            Twig::create(Settings::getAppRoot() . '/tmpl'),
            $this->entityManager,
            new BrainRegistry($settings, $container),
            new ComfyUIWorkflowRegistry($settings),
            $settings,
        );
    }

    public function testTelegramFormReturns401WhenNotAuthenticated(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn(null);

        $request = $this->createRequestWithSession();
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegramForm($request, $response);

        self::assertSame(401, $result->getStatusCode());
    }

    public function testTelegramFormReturnsHtmlForm(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->user->method('getTelegramId')->willReturn('987654321');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);

        $request = $this->createRequestWithSession();
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegramForm($request, $response);

        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('987654321', $body);
        self::assertStringContainsString('Compte associé', $body);
    }

    public function testTelegramRejectsNonNumericId(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);

        $request = $this->createRequestWithSession(parsedBody: ['telegram_id' => 'abc123xyz']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegram($request, $response);

        self::assertSame(422, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('doit être composé uniquement de chiffres', $body);
    }

    public function testTelegramRejectsDuplicateIdFromAnotherUser(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);

        $otherUser = $this->createMock(User::class);
        $otherUser->method('getId')->willReturn('other-user-456');

        $this->userRepository->method('__call')->willReturnCallback(function ($method, $args) use ($otherUser) {
            if ($method === 'findByTelegramId' && $args[0] === '123456789') {
                return $otherUser;
            }

            return null;
        });

        $request = $this->createRequestWithSession(parsedBody: ['telegram_id' => '123456789']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegram($request, $response);

        self::assertSame(409, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('déjà associé à un autre compte', $body);
    }

    public function testTelegramSavesValidIdSuccessfully(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);
        $this->user->method('getTelegramId')->willReturn('123456789');

        $this->user->expects(self::once())->method('setTelegramId')->with('123456789');
        $this->entityManager->expects(self::once())->method('flush');

        $request = $this->createRequestWithSession(parsedBody: ['telegram_id' => '123456789']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegram($request, $response);

        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('Configuration Telegram enregistrée avec succès.', $body);
        self::assertStringContainsString('123456789', $body);
    }

    public function testTelegramUnlinksAccountWhenEmpty(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);
        $this->user->method('getTelegramId')->willReturn(null);

        $this->user->expects(self::once())->method('setTelegramId')->with(null);
        $this->entityManager->expects(self::once())->method('flush');

        $request = $this->createRequestWithSession(parsedBody: ['telegram_id' => '']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegram($request, $response);

        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('Association Telegram supprimée avec succès.', $body);
        self::assertStringContainsString('Non associé', $body);
    }

    private function createRequestWithSession(array $parsedBody = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getAttribute')
            ->willReturnCallback(function (string $name) {
                return match ($name) {
                    JwtSessionMiddleware::SESSION_ATTRIBUTE => $this->session,
                    default => null,
                };
            });

        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }
}
