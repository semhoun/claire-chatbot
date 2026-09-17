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
use App\Services\ChatTurnJournal;
use App\Services\Queue\NonRetryableJobException;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use App\Services\ThemeRegistry;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

    private readonly \Closure $opening;

    public function __construct(
        ContainerInterface $container,
    ) {
        $this->userChatHistory = $container->get(UserChatHistory::class);
        $this->opening = $container->get('test.opening');
    }

    #[\Override]
    public function getOpeningText(): string
    {
        return ($this->opening)($this->userChatHistory);
    }

    #[\Override]
    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->userChatHistory;
    }
}

final class OpeningCommitTestConnection extends Connection
{
    public bool $loseAcknowledgement = false;

    public function transactional(\Closure $func): mixed
    {
        $result = parent::transactional($func);
        if ($this->loseAcknowledgement) {
            $this->loseAcknowledgement = false;
            throw new \RuntimeException('Commit acknowledgement lost');
        }
        return $result;
    }
}

#[AllowMockObjectsWithoutExpectations]
final class StartThreadJobTest extends TestCase
{
    private OpeningCommitTestConnection $connection;
    private StartThreadJob $job;
    private ChatTurnJournal $journal;
    private array $state = [];
    private array $events = [];
    private int $calls = 0;
    private ?UserChatHistory $history = null;
    private \Closure $opening;
    private bool $failPublication = false;

    protected function setUp(): void
    {
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => OpeningCommitTestConnection::class,
        ]);
        \App\Test\Support\ChatTurnSqlSchema::create($this->connection);
        $this->journal = new ChatTurnJournal($this->connection);
        $this->opening = function (UserChatHistory $history): string {
            self::assertSame('running', $this->journal->get('opening-web-thread-1')['status']);
            $history->replaceMessages([
                new UserMessage('[OC]Generate a welcome message[/OC]'),
                new AssistantMessage('Bienvenue générée'),
            ]);
            return 'Bienvenue générée';
        };
        $settings = new Settings([
            'llm' => [
                'brains' => ['claire' => GeneratedOpeningTestAgent::class],
                'yamlBrains' => ['path' => '/tmp/missing-brains'],
            ],
            'redis' => ['prefix' => 'claire:'],
        ]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id): mixed {
            if ($id === UserChatHistory::class) {
                return $this->history = new UserChatHistory(
                    new InMemorySession($this->payload()['session']),
                    $this->connection->getNativeConnection(), threadId: 'web-thread-1',
                );
            }
            self::assertSame('test.opening', $id);
            return function (UserChatHistory $history): string {
                ++$this->calls;
                return ($this->opening)($history);
            };
        });
        $brainRegistry = new BrainRegistry($settings, $container, new ThemeRegistry($settings));
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturnCallback(fn (): array => $this->state);
        $redis->method('hset')->willReturnCallback(function (string $key, array $values): int {
            $this->state = array_replace($this->state, array_map(strval(...), $values));
            return 1;
        });
        $redis->method('publish')->willReturnCallback(function (string $key, string $message): int {
            if ($this->failPublication) {
                throw new \RuntimeException('Publication failed');
            }
            $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if ($event['event'] === 'chat.snapshot') {
                $lock = new \App\Services\ChatThreadLock(
                    $this->connection->getNativeConnection(), 'user-1', 'web-thread-1',
                );
                $lock->release();
            }
            if ($event['event'] === 'chat.error' && ($event['payload']['rollbackConfirmed'] ?? false)) {
                self::assertSame('rolled_back', $this->journal->get('opening-web-thread-1')['status']);
                self::assertSame('[]', $this->connection->fetchOne('SELECT messages FROM chat_history'));
            }
            $this->events[] = $event;
            return 1;
        });
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
        $this->job = new StartThreadJob($chatDataRenderer, $brainRegistry, $chatStreamPublisher, $this->connection);
    }

    public function testSupersededOpeningAcknowledgesWithoutAgentOrEvents(): void
    {
        $this->state = ['messageId' => 'newer-message', 'status' => 'queued'];
        $this->job->handle($this->payload());
        self::assertSame(0, $this->calls);
        self::assertSame([], $this->events);
        self::assertSame(['messageId' => 'newer-message', 'status' => 'queued'], $this->state);
    }

    public function testGeneratedOpeningMessageReplacesTechnicalLlmHistory(): void
    {
        $this->job->handle($this->payload());
        $userChatHistory = $this->history;
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
        self::assertSame('succeeded', $this->journal->get('opening-web-thread-1')['status']);
        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertSame('chat.snapshot', $event['event']);
        self::assertArrayNotHasKey('html', $event['payload']);
        self::assertSame('Bienvenue générée', $event['payload']['messages'][0]['message']);
        self::assertFalse($event['payload']['responding']);
        self::assertNull($event['payload']['activeMessageId']);
    }

    public function testRedisLossCannotReplayOpeningAfterLaterConversation(): void
    {
        $this->job->handle($this->payload());
        $this->journal->begin('later', 'user-1', 'web-thread-1', 'web', 'later');
        $history = new UserChatHistory(new InMemorySession($this->payload()['session']),
            $this->connection->getNativeConnection(), threadId: 'web-thread-1');
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new AssistantMessage('Answer'));
        $this->journal->succeed('later', 'user-1');
        $before = $this->connection->fetchAssociative('SELECT * FROM chat_history');
        $this->state = $this->events = [];
        $this->job->handle($this->payload());
        self::assertSame(1, $this->calls);
        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM chat_history'));
        self::assertSame([], $this->events);
        self::assertSame([], $this->state);
    }

    public function testExistingUsedHistoryWithoutOpeningJournalIsNotReinitialized(): void
    {
        $history = new UserChatHistory(new InMemorySession($this->payload()['session']),
            $this->connection->getNativeConnection(), threadId: 'web-thread-1');
        $history->addMessage(new UserMessage('Existing question'));
        $history->addMessage(new AssistantMessage('Existing answer'));
        $before = $this->connection->fetchAssociative('SELECT * FROM chat_history');
        $this->job->handle($this->payload());
        self::assertSame(0, $this->calls);
        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM chat_history'));
        self::assertNull($this->journal->get('opening-web-thread-1'));
    }

    public function testDeletedTombstoneWithoutOpeningJournalCannotResurrectHistory(): void
    {
        $this->journal->begin('later', 'user-1', 'web-thread-1', 'web', 'later');
        $this->connection->transactional(function (): void {
            $this->journal->neutralize('user-1', 'web-thread-1');
            $this->connection->executeStatement('DELETE FROM chat_history');
        });
        $this->job->handle($this->payload());
        self::assertSame(0, $this->calls);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM chat_history'));
        self::assertNull($this->journal->get('opening-web-thread-1'));
        self::assertSame([], $this->events);
    }

    public function testFailureRollsBackBeforeNotificationAndNeverReplaysAfterRedisLoss(): void
    {
        $this->opening = static function (UserChatHistory $history): never {
            $history->addMessage(new UserMessage('Partial opening'));
            throw new \RuntimeException('Agent failed after tool');
        };
        try {
            $this->job->handle($this->payload());
            self::fail('Expected terminal failure');
        } catch (NonRetryableJobException) {
        }
        self::assertSame('rolled_back', $this->journal->get('opening-web-thread-1')['status']);
        self::assertSame('[]', $this->connection->fetchOne('SELECT messages FROM chat_history'));
        self::assertSame('[]', $this->connection->fetchOne('SELECT display_messages FROM chat_history'));
        self::assertTrue($this->events[0]['payload']['rollbackConfirmed']);
        self::assertArrayNotHasKey('submissionId', $this->events[0]['payload']);
        self::assertArrayNotHasKey('draft', $this->events[0]['payload']);
        $this->state = [];
        $this->job->handle($this->payload());
        self::assertSame(1, $this->calls);
    }

    public function testLostBeginAcknowledgementCannotEnterAgentOnRetry(): void
    {
        $this->connection->loseAcknowledgement = true;
        try {
            $this->job->handle($this->payload());
            self::fail('Expected ambiguous entry to be terminal');
        } catch (NonRetryableJobException) {
        }
        self::assertSame('rolled_back', $this->journal->get('opening-web-thread-1')['status']);
        $this->state = [];
        $this->job->handle($this->payload());
        self::assertSame(0, $this->calls);
    }

    public function testDeletedOpeningTombstoneCannotResurrectHistory(): void
    {
        $this->job->handle($this->payload());
        $this->connection->transactional(function (): void {
            $this->journal->neutralize('user-1', 'web-thread-1');
            $this->connection->executeStatement('DELETE FROM chat_history');
        });
        $this->state = $this->events = [];
        $this->job->handle($this->payload());
        self::assertSame(1, $this->calls);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM chat_history'));
        self::assertSame([], $this->state);
        self::assertSame([], $this->events);
    }

    public function testAbandonedFenceRollsBackWithoutCallingAgent(): void
    {
        $this->journal->begin('opening-web-thread-1', 'user-1', 'web-thread-1', 'web', 'opening-web-thread-1');
        $this->connection->executeStatement("UPDATE chat_history SET messages = '[\"partial\"]'");
        try {
            $this->job->handle($this->payload());
            self::fail('Expected abandoned opening failure');
        } catch (NonRetryableJobException) {
        }
        self::assertSame(0, $this->calls);
        self::assertSame('rolled_back', $this->journal->get('opening-web-thread-1')['status']);
        self::assertSame('[]', $this->connection->fetchOne('SELECT messages FROM chat_history'));
        $this->state = [];
        $this->job->handle($this->payload());
        self::assertSame(0, $this->calls);
    }

    public function testLostSuccessCommitAcknowledgementDoesNotUndoOpening(): void
    {
        $opening = $this->opening;
        $this->opening = function (UserChatHistory $history) use ($opening): string {
            $message = $opening($history);
            $this->connection->loseAcknowledgement = true;
            return $message;
        };
        $this->job->handle($this->payload());
        self::assertSame('succeeded', $this->journal->get('opening-web-thread-1')['status']);
        $this->state = [];
        $this->job->handle($this->payload());
        self::assertSame(1, $this->calls);
        self::assertSame([], $this->events);
    }

    public function testSnapshotFailureAfterCommitCannotReplayOpening(): void
    {
        $this->failPublication = true;
        try {
            $this->job->handle($this->payload());
            self::fail('Expected publication failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('Publication failed', $exception->getMessage());
        }
        self::assertSame('succeeded', $this->journal->get('opening-web-thread-1')['status']);
        $this->state = [];
        $this->job->handle($this->payload());
        self::assertSame(1, $this->calls);
    }

    private function payload(): array
    {
        return [
            'threadId' => 'web-thread-1', 'sessionId' => 'session-1',
            'session' => [Auth::USERID => 'user-1', 'brain_avatar' => 'claire'],
        ];
    }
}
