<?php

declare(strict_types=1);

namespace Test\Unit\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Job\Web\StartThreadJob;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Session\InMemorySession;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

final class GeneratedOpeningTestAgent extends Agent implements \App\Brain\BrainAvatar
{
    public const string NAME = 'Test';
    public const string DESCRIPTION = 'Test';
    public const string AVATAR = '';
    public const string CSS = '';

    private UserChatHistory $testHistory;

    public function __construct(
        ContainerInterface $container,
        SessionInterface $session,
        ?string $threadId = null,
    ) {
        $this->testHistory = $container->get(UserChatHistory::class);
    }

    public function getOpeningText(): string
    {
        return 'Bienvenue générée';
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->testHistory;
    }
}

#[AllowMockObjectsWithoutExpectations]
final class StartThreadJobTest extends TestCase
{
    public function testGeneratedOpeningMessageDoesNotPolluteLlmHistory(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            "CREATE TABLE chat_history (user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY, messages TEXT NOT NULL, display_messages TEXT NOT NULL DEFAULT '[]', display_messages_count INTEGER NOT NULL DEFAULT 0, title TEXT DEFAULT NULL, summary TEXT DEFAULT NULL)"
        );

        $session = new InMemorySession([
            Auth::USERID => 'user-1',
            'brain_avatar' => 'claire',
        ]);
        $history = new UserChatHistory($session, $pdo, threadId: 'web-thread-1');
        $history->replaceMessages([
            new UserMessage('[OC]Generate a welcome message[/OC]'),
            new AssistantMessage('Bienvenue générée'),
        ]);

        $twig = $this->createMock(Twig::class);
        $twig->method('fetch')->willReturn('<article>Bienvenue générée</article>');

        $settings = new Settings([
            'llm' => [
                'brains' => ['claire' => GeneratedOpeningTestAgent::class],
                'yamlBrains' => ['path' => '/tmp/missing-brains'],
            ],
            'redis' => ['prefix' => 'claire:'],
            'sse' => ['queue_ttl' => 3600],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(UserChatHistory::class)->willReturn($history);
        $brainRegistry = new BrainRegistry($settings, $container);
        $redis = $this->createMock(RedisClient::class);
        $publisher = new ChatStreamPublisher(
            $redis,
            new ChatStreamSubscriber($redis, $settings),
            $settings,
        );

        $job = new StartThreadJob($twig, $brainRegistry, $publisher);
        $job->handle([
            'threadId' => 'web-thread-1',
            'sessionId' => 'session-1',
            'session' => $session->all(),
        ]);

        self::assertSame([], $history->getMessages());
        self::assertCount(1, $history->getDisplayMessages());
        self::assertSame(
            'Bienvenue générée',
            $history->getDisplayMessages()[0]->getContent(),
        );
    }
}
