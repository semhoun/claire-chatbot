<?php

declare(strict_types=1);

namespace Test\Unit\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Job\Web\StartThreadJob;
use App\Renderer\ChatDataRenderer;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use App\Services\ThemeRegistry;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class GeneratedOpeningTestAgent extends Agent implements \App\Brain\BrainAvatar
{
    public const string NAME = 'Test';

    public const string DESCRIPTION = 'Test';

    public const string AVATAR = '';

    public const string THEME = 'cyberpunk';

    private readonly UserChatHistory $userChatHistory;

    public function __construct(
        ContainerInterface $container,
    ) {
        $this->userChatHistory = $container->get(UserChatHistory::class);
    }

    #[\Override]
    public function getOpeningText(): string
    {
        return 'Bienvenue générée';
    }

    #[\Override]
    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->userChatHistory;
    }
}

#[AllowMockObjectsWithoutExpectations]
final class StartThreadJobTest extends TestCase
{
    public function testSupersededOpeningAcknowledgesWithoutAgentOrEvents(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('hgetall')->willReturn(['messageId' => 'newer-message', 'status' => 'queued']);
        $redis->expects(self::never())->method('hset');
        $redis->expects(self::never())->method('publish');
        $publisher = new ChatStreamPublisher($redis, new ChatStreamSubscriber($settings), $settings);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $renderer = new ChatDataRenderer(new GeneratedFileProcessor(
            $settings, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        ));
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $job = new StartThreadJob($renderer,
            new BrainRegistry($settings, $container, new ThemeRegistry($settings)), $publisher, $connection);
        $job->handle(['threadId' => 'thread', 'sessionId' => 'tab', 'session' => [Auth::USERID => 'user']]);
    }

    public function testGeneratedOpeningMessageReplacesTechnicalLlmHistory(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            "CREATE TABLE chat_history (user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY, messages TEXT NOT NULL, display_messages TEXT NOT NULL DEFAULT '[]', display_messages_count INTEGER NOT NULL DEFAULT 0, title TEXT DEFAULT NULL, summary TEXT DEFAULT NULL)"
        );

        $inMemorySession = new InMemorySession([
            Auth::USERID => 'user-1',
            'brain_avatar' => 'claire',
        ]);
        $userChatHistory = new UserChatHistory($inMemorySession, $pdo, threadId: 'web-thread-1');
        $userChatHistory->replaceMessages([
            new UserMessage('[OC]Generate a welcome message[/OC]'),
            new AssistantMessage('Bienvenue générée'),
        ]);

        $settings = new Settings([
            'llm' => [
                'brains' => ['claire' => GeneratedOpeningTestAgent::class],
                'yamlBrains' => ['path' => '/tmp/missing-brains'],
            ],
            'redis' => ['prefix' => 'claire:'],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(UserChatHistory::class)->willReturn($userChatHistory);
        $brainRegistry = new BrainRegistry($settings, $container, new ThemeRegistry($settings));
        $redis = $this->createMock(RedisClient::class);
        $redis->method('hgetall')->willReturn([]);
        $redis->method('hset')->willReturn(1);
        $redis->expects(self::once())->method('publish')->with(
            self::anything(),
            self::callback(static function (string $message) use ($pdo): bool {
                $lock = new \App\Services\ChatThreadLock($pdo, 'user-1', 'web-thread-1');
                $lock->release();
                $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('html', $event['payload']);
                self::assertSame('Bienvenue générée', $event['payload']['messages'][0]['message']);
                return $event['event'] === 'chat.snapshot'
                    && $event['payload']['responding'] === false
                    && $event['payload']['activeMessageId'] === null;
            }),
        )->willReturn(1);
        $redis->method('expire')->willReturn(true);
        $chatStreamPublisher = new ChatStreamPublisher(
            $redis,
            new ChatStreamSubscriber($settings),
            $settings,
        );

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $chatDataRenderer = new ChatDataRenderer(
            new GeneratedFileProcessor($settings, $entityManager)
        );
        $connection = $this->createStub(\Doctrine\DBAL\Connection::class);
        $connection->method('getNativeConnection')->willReturn($pdo);
        $startThreadJob = new StartThreadJob($chatDataRenderer, $brainRegistry, $chatStreamPublisher, $connection);
        $startThreadJob->handle([
            'threadId' => 'web-thread-1',
            'sessionId' => 'session-1',
            'session' => $inMemorySession->all(),
        ]);

        self::assertCount(2, $userChatHistory->getMessages());
        self::assertInstanceOf(UserMessage::class, $userChatHistory->getMessages()[0]);
        self::assertSame(
            'out_of_context',
            $userChatHistory->getMessages()[0]->getMetadata('message_type'),
        );
        self::assertSame(
            'Bienvenue générée',
            $userChatHistory->getMessages()[1]->getContent(),
        );
        self::assertStringNotContainsString(
            'Generate a welcome message',
            (string) $userChatHistory->getMessages()[0]->getContent(),
        );
        self::assertCount(1, $userChatHistory->getDisplayMessages());
        self::assertSame(
            'Bienvenue générée',
            $userChatHistory->getDisplayMessages()[0]->getContent(),
        );
    }
}
