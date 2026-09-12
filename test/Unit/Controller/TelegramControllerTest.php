<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\TelegramController;
use App\Entity\User;
use App\Renderer\JsonRenderer;
use App\Repository\UserRepository;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Settings;
use App\Services\TelegramService;
use App\Services\TelegramValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TelegramControllerTest extends TestCase
{
    public function testEnqueueFailureReturnsRetryable503(): void
    {
        $dispatcher = $this->createMock(QueueDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->willThrowException(new RuntimeException('Redis down'));
        $controller = $this->controller($dispatcher);
        $response = $controller->webhook($this->request(), new Response());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Retry-After'));
    }

    public function testSuccessfulEnqueueReturns204AndPreservesUpdateId(): void
    {
        $dispatcher = $this->createMock(QueueDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->with(TelegramService::class, ['update_json' => '{"update_id":123}'], 'shared')
            ->willReturn('job-id');
        self::assertSame(204, $this->controller($dispatcher)->webhook(
            $this->request(), new Response(),
        )->getStatusCode());
    }

    public function testInvalidSecretDoesNotEnqueue(): void
    {
        $dispatcher = $this->createMock(QueueDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $response = $this->controller($dispatcher)->webhook(
            $this->request()->withoutHeader('X-Telegram-Bot-Api-Secret-Token'), new Response(),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function testNewChatApiEnqueueFailureReturns503(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findByTelegramId')->willReturn($this->createStub(User::class));
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $dispatcher = $this->createMock(QueueDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->willThrowException(new RuntimeException('Redis down'));
        $user = '{"id":42}';
        $hash = hash_hmac('sha256', 'user=' . $user, hash_hmac('sha256', 'token', 'WebAppData', true));
        $request = $this->request()->withParsedBody([
            'action' => 'new_chat',
            'initData' => http_build_query(['user' => $user, 'hash' => $hash]),
        ]);
        $response = $this->controller($dispatcher, $manager)->api($request, new Response());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Retry-After'));
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/telegram/webhook')
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret');
        $request->getBody()->write('{"update_id":123}');
        $request->getBody()->rewind();
        return $request;
    }

    private function controller(
        QueueDispatcherInterface $dispatcher,
        ?EntityManagerInterface $manager = null,
    ): TelegramController {
        $settings = new Settings([
            'telegram' => ['webhook_secret' => 'secret', 'bot_token' => 'token'],
            'queue' => ['defaultQueue' => 'shared'],
        ]);
        $controller = new ReflectionClass(TelegramController::class)->newInstanceWithoutConstructor();
        foreach ([
            'logger' => new NullLogger(),
            'settings' => $settings,
            'queueDispatcher' => $dispatcher,
            'jsonRenderer' => new JsonRenderer(),
            'telegramService' => $this->createStub(TelegramService::class),
            'telegramValidator' => new TelegramValidator(
                new NullLogger(), $manager ?? $this->createStub(EntityManagerInterface::class), $settings,
            ),
        ] as $property => $value) {
            new ReflectionProperty($controller, $property)->setValue($controller, $value);
        }
        return $controller;
    }
}
