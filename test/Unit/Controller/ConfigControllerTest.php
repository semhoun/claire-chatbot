<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Brain\BrainRegistry;
use App\Controller\ConfigController;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\Audio\AudioServiceInterface;
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
        $audioService = $this->createStub(AudioServiceInterface::class);
        $audioService->method('defaultVoice')->willReturn('voice-1');
        $audioService->method('isAllowedVoice')->willReturnCallback(
            static fn (string $voice): bool => $voice === 'voice-1',
        );

        $this->controller = new ConfigController(
            $this->entityManager,
            new BrainRegistry($settings, $container),
            new ComfyUIWorkflowRegistry($settings),
            $settings,
            $audioService,
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

    public function testAudioPreferencesArePersisted(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);
        $this->user->method('getParams')->willReturn([]);

        $this->session->expects(self::exactly(4))->method('set');
        $this->user->expects(self::once())->method('setParams')->with([
            AudioServiceInterface::ENABLED_SESSION_KEY => true,
            AudioServiceInterface::AUTO_GENERATE_SESSION_KEY => true,
            AudioServiceInterface::DICTATION_MODE_SESSION_KEY => 'auto_send',
            AudioServiceInterface::VOICE_SESSION_KEY => 'voice-1',
        ]);
        $this->entityManager->expects(self::once())->method('flush');

        $request = $this->createRequestWithSession(parsedBody: [
            'enabled' => true,
            'auto_generate' => true,
            'dictation_mode' => 'auto_send',
            'voice' => 'voice-1',
        ]);
        $result = $this->controller->audio(
            $request,
            $this->responseFactory->createResponse(),
        );

        self::assertSame(204, $result->getStatusCode());
    }

    public function testAudioPreferencesRejectUnknownVoice(): void
    {
        $request = $this->createRequestWithSession(parsedBody: [
            'voice' => 'unknown',
        ]);
        $result = $this->controller->audio(
            $request,
            $this->responseFactory->createResponse(),
        );

        self::assertSame(400, $result->getStatusCode());
    }

    public function testTelegramFormReturnsJsonState(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->user->method('getTelegramId')->willReturn('987654321');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);

        $request = $this->createRequestWithSession();
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegramForm($request, $response);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        self::assertSame(['telegramId' => '987654321', 'success' => null, 'error' => null],
            json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTelegramRejectsNonNumericId(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->userRepository->method('find')->with('user-123')->willReturn($this->user);

        $request = $this->createRequestWithSession(parsedBody: ['telegram_id' => 'abc123xyz']);
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->telegram($request, $response);

        self::assertSame(422, $result->getStatusCode());
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $body = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['success']);
        self::assertStringContainsString('doit être composé uniquement de chiffres', $body['error']);
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
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $body = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['success']);
        self::assertStringContainsString('déjà associé à un autre compte', $body['error']);
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
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        self::assertSame([
            'telegramId' => '123456789',
            'success' => 'Configuration Telegram enregistrée avec succès.',
            'error' => null,
        ], json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR));
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
        self::assertSame([
            'telegramId' => null,
            'success' => 'Association Telegram supprimée avec succès.',
            'error' => null,
        ], json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR));
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
