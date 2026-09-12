<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\Agent;
use App\Brain\BrainAvatar;
use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Entity\TelegramSession as SessionEntity;
use App\Repository\TelegramSessionRepository;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Session\SessionInterface;
use App\Services\Session\TelegramSession;
use App\Services\Settings;
use App\Services\TelegramService;
use NeuronAI\Chat\History\ChatHistoryInterface;
use Phptg\BotApi\Type\Update\Update;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;

final class TelegramSessionLifecycleTest extends TestCase
{
    public function testBrainCommandSavesThenFinalizesWithoutUnloadedSessionError(): void
    {
        [$service, $entity] = $this->service();
        $service->processUpdate($this->update('/brain second'));
        self::assertSame('second', $entity->getSessionData()['brain_avatar']);
        self::assertCount(1, $service->sent);
    }

    public function testStartCommandSavesThenFinalizesWithoutUnloadedSessionError(): void
    {
        [$service, $entity] = $this->service();
        $service->processUpdate($this->update('/start'));
        self::assertStringStartsWith(UserChatHistory::CHAT_TELEGRAM, $entity->getSessionData()['threadId']);
        self::assertSame(['Welcome'], $service->sent);
    }

    public function testWorkflowCommandSavesThenFinalizes(): void
    {
        [$service, $entity] = $this->service();
        $service->processUpdate($this->update('/comfyui test'));
        self::assertSame('test', $entity->getSessionData()['comfyui_workflow']);
        self::assertCount(1, $service->sent);
    }

    public function testProcessingFailureIsNotSwallowed(): void
    {
        [$service] = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $service->manageSession('42');
        $session = new ReflectionProperty(TelegramService::class, 'telegramSession')->getValue($service);
        $session->set('brain_avatar', 'unknown');
        $session->save();
        $service->processUpdate($this->update('/start'));
    }

    public function testDirectCallsPersistAndAllowMultipleSettingsUpdates(): void
    {
        [$service, $entity] = $this->service();
        self::assertTrue($service->manageSession('42'));
        self::assertTrue($service->updateUserSetting('brain_avatar', 'second'));
        self::assertTrue($service->updateUserSetting('brain_avatar', 'first'));
        $service->startNewChat(42);
        self::assertSame('first', $entity->getSessionData()['brain_avatar']);
        self::assertStringStartsWith(UserChatHistory::CHAT_TELEGRAM, $entity->getSessionData()['threadId']);
    }

    /** @return array{LifecycleTelegramService, SessionEntity} */
    private function service(): array
    {
        $entity = new SessionEntity();
        $entity->setSessionData([Auth::AUTHENTICATED => true, 'brain_avatar' => 'first']);
        $repository = $this->createStub(TelegramSessionRepository::class);
        $repository->method('findOrCreateByTelegramId')->willReturn($entity);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $settings = new Settings([
            'llm' => ['brains' => ['first' => LifecycleBrain::class, 'second' => LifecycleBrain::class]],
            'tools' => ['comfyui' => ['enabled' => true]],
        ]);
        $history = $this->createStub(UserChatHistory::class);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($history);
        $registry = new BrainRegistry($settings, $container);
        new ReflectionProperty($registry, 'yamlBrainsCache')->setValue($registry, []);
        $workflows = new ComfyUIWorkflowRegistry($settings);
        new ReflectionProperty($workflows, 'cache')->setValue($workflows, [
            'test' => ['label' => 'Test', 'workflow' => 'unused.json'],
        ]);
        $service = new ReflectionClass(LifecycleTelegramService::class)->newInstanceWithoutConstructor();
        foreach ([
            'telegramSession' => new TelegramSession($manager),
            'settings' => $settings,
            'brainRegistry' => $registry,
            'comfyUIWorkflowRegistry' => $workflows,
            'logger' => new NullLogger(),
        ] as $property => $value) {
            new ReflectionProperty(TelegramService::class, $property)->setValue($service, $value);
        }
        return [$service, $entity];
    }

    private function update(string $command): Update
    {
        return Update::fromJson(json_encode([
            'update_id' => 1,
            'message' => [
                'message_id' => 1, 'date' => 1,
                'chat' => ['id' => 42, 'type' => 'private'],
                'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Test'],
                'text' => $command,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}

final class LifecycleTelegramService extends TelegramService
{
    /** @var list<string> */
    public array $sent = [];

    public function sendMessage(int $telegramChatId, string $text): void
    {
        $this->sent[] = $text;
    }
}

final class LifecycleBrain extends Agent implements BrainAvatar
{
    private ChatHistoryInterface $testHistory;

    public function __construct(ContainerInterface $container, SessionInterface $session, ?string $threadId = null)
    {
        $this->testHistory = $container->get(UserChatHistory::class);
    }

    public function getOpeningText(): string
    {
        return 'Welcome';
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->testHistory;
    }
}
