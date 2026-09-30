<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Controller\TelegramController;
use App\Services\ChatStopRequests;
use App\Services\ChatThreadLock;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Settings;
use App\Services\TelegramJournal;
use App\Services\TelegramStopIngress;
use App\Services\TelegramValidator;
use App\Test\Support\ChatTurnSqlSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Migrations\Version20260930000100;
use Migrations\Version20260930000300;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\TransportInterface;
use Phptg\BotApi\Type\Update\Update;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TelegramStopIngressTest extends TestCase
{
    private Connection $sql;
    private ChatStopRequests $stops;
    private TelegramStopIngress $ingress;
    private Settings $settings;
    private array $notices = [];
    private bool $failNotice = false;

    protected function setUp(): void
    {
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        $this->sql = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        ChatTurnSqlSchema::create($this->sql);
        foreach ([Version20260930000100::class, Version20260930000300::class] as $class) {
            $migration = new $class($this->sql, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->sql->executeStatement($query->getStatement());
            }
        }
        $this->sql->executeStatement('CREATE TABLE account (id VARCHAR(64), telegram_id VARCHAR(64))');
        $this->sql->executeStatement('CREATE TABLE telegram_session (telegram_id VARCHAR(64), session_data TEXT)');
        $this->sql->insert('account', ['id' => 'owner', 'telegram_id' => '42']);
        $this->sql->insert('account', ['id' => 'other', 'telegram_id' => '43']);
        $this->settings = new Settings(['llm' => ['stop' => ['enabled' => true]],
            'queue' => ['defaultQueue' => 'shared'],
            'telegram' => ['bot_token' => '123:token', 'bot_username' => 'ClaireBot', 'webhook_secret' => 'secret']]);
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('post')->willReturnCallback(function (string $url, string $data): ApiResponse {
            self::assertStringEndsWith('/sendMessage', $url);
            $this->notices[] = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
            if ($this->failNotice) {
                throw new \RuntimeException('Uncertain network delivery');
            }
            return new ApiResponse(200, '{"ok":true,"result":{"message_id":1,"date":1,'
                . '"chat":{"id":42,"type":"private"},"text":"notice"}}');
        });
        $this->stops = new ChatStopRequests($this->sql);
        $this->ingress = new TelegramStopIngress($this->sql, $this->stops, $this->settings,
            new TelegramBotApi('123:token', transport: $transport), new NullLogger());
    }

    public function testWebhookStopsWithSingleWorkerOccupiedAndConversationLockHeld(): void
    {
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        $this->runningTurn($target);
        $queued = $this->ingress->register('123', 12, 'owner', 'thread', -100, 8);
        $workerLock = new ChatThreadLock($this->sql->getNativeConnection(), 'owner', 'thread');
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::never())->method('dispatch');
        // The only worker holds the lock and cannot consume another queue job.
        $controller = $this->controller($queue);
        $request = new ServerRequestFactory()->createServerRequest('POST', '/telegram/webhook')
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret');
        $request->getBody()->write($this->json(13, '/stop@ClaireBot'));
        $request->getBody()->rewind();
        try {
            self::assertSame(204, $controller->webhook($request, new Response())->getStatusCode());
            self::assertTrue($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
            self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $queued['id']));
            $workerLock->assertHeld();
            self::assertCount(1, $this->notices);
            self::assertSame(8, $this->notices[0]['message_thread_id']);
        } finally {
            $workerLock->release();
        }
    }

    public function testRetryCannotStopNextGenerationOrChangeAcceptedThread(): void
    {
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        $this->runningTurn($target);
        $next = $this->ingress->register('123', 12, 'owner', 'thread', -100, 8);
        self::assertSame('thread', $this->ingress->register('123', 10, 'owner', 'next-thread', -100, 8)['thread_id']);
        $update = Update::fromJson($this->json(13));
        self::assertTrue($this->ingress->handle($update));
        self::assertSame($target['id'], $this->sql->fetchOne('SELECT target_id FROM telegram_stop_update'));
        $this->sql->transactional(fn () => $this->stops->arbitrateTerminal(
            'owner', 'thread', 'telegram', $target['id'], 'succeeded',
        ));
        $this->sql->update('chat_turn', ['status' => 'stopped'], ['id' => $target['id']]);
        $this->runningTurn($next);
        self::assertTrue($this->ingress->handle($update));
        self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $next['id']));
        self::assertCount(1, $this->notices);
    }

    public function testRunningPriorityIsScopedToBotOwnerChatAndTopicWithQueuedFallback(): void
    {
        $running = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        $this->runningTurn($running);
        $queued = $this->ingress->register('123', 12, 'owner', 'thread', -100, 8);
        $isolated = [];
        foreach ([['999', 20, 'owner', 'thread', -100, 8], ['123', 21, 'other', 'thread', -100, 8],
            ['123', 22, 'owner', 'thread', -101, 8], ['123', 23, 'owner', 'thread', -100, 9],
            ['123', 80, 'owner', 'thread', -100, 8]] as $identity) {
            $target = $this->ingress->register(...$identity);
            $this->runningTurn($target);
            $isolated[] = $target;
        }
        self::assertTrue($this->ingress->handle(Update::fromJson($this->json(50))));
        self::assertTrue($this->stops->isRequested('owner', 'thread', 'telegram', $running['id']));
        self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $queued['id']));
        $this->sql->transactional(fn () => $this->stops->arbitrateTerminal(
            'owner', 'thread', 'telegram', $running['id'], 'stopped',
        ));
        $this->sql->update('chat_turn', ['status' => 'stopped'], ['id' => $running['id']]);
        // Unrelated running turns cannot prevent selecting the matching queued acceptance.
        self::assertTrue($this->ingress->handle(Update::fromJson($this->json(51))));
        self::assertTrue($this->stops->isRequested('owner', 'thread', 'telegram', $queued['id']));
        foreach ($isolated as $target) {
            self::assertFalse($this->stops->isRequested($target['user_id'], $target['thread_id'],
                'telegram', $target['id']));
        }
    }

    public function testRunningJoinRequiresExactGenerationOwnerThreadChannelAndStatus(): void
    {
        foreach ([['id' => str_repeat('b', 64)], ['generation_id' => str_repeat('a', 64)], ['user_id' => 'other'],
            ['thread_id' => 'other-thread'], ['channel' => 'web'], ['status' => 'succeeded']] as $index => $mismatch) {
            $topic = 100 + $index;
            $older = $this->ingress->register('123', 100 + 3 * $index, 'owner', 'thread', -100, $topic);
            $this->runningTurn($older, $mismatch);
            $queued = $this->ingress->register('123', 101 + 3 * $index, 'owner', 'thread', -100, $topic);
            $this->ingress->handle(Update::fromJson($this->json(102 + 3 * $index, '/stop', 42, -100, $topic)));
            self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $older['id']));
            self::assertTrue($this->stops->isRequested('owner', 'thread', 'telegram', $queued['id']));
        }
    }

    public function testAcceptanceFreezesSqlSessionThreadBeforeDispatchAndSkipsCommands(): void
    {
        $this->sql->insert('telegram_session', ['telegram_id' => '42', 'session_data' => '{"threadId":"original"}']);
        $target = $this->ingress->accept(Update::fromJson($this->json(10, 'hello')));
        self::assertSame(TelegramJournal::id('123', 'update:10'), $target['id']);
        self::assertSame('original', $target['thread_id']);
        $this->sql->update('telegram_session', ['session_data' => '{"threadId":"new"}'], ['telegram_id' => '42']);
        self::assertSame('original', $this->ingress->accept(Update::fromJson($this->json(10, 'hello')))['thread_id']);
        self::assertNull($this->ingress->accept(Update::fromJson($this->json(11, '/start'))));
        self::assertNull($this->ingress->accept(Update::fromJson($this->json(12, 'hello', 99))));
        self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM telegram_stop_target'));
    }

    public function testMissingSessionGetsStableThreadWithoutCreatingSourceSession(): void
    {
        $first = $this->ingress->accept(Update::fromJson($this->json(10, 'hello')));
        self::assertStringStartsWith('telegram', $first['thread_id']);
        self::assertSame($first, $this->ingress->accept(Update::fromJson($this->json(10, 'hello'))));
        self::assertSame(0, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM telegram_session'));
    }

    public function testEnqueueFailurePreservesAcceptanceAndStopCanTargetIt(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(function (): never {
            self::assertSame(1, (int) $this->sql->fetchOne('SELECT COUNT(*) FROM telegram_stop_target'));
            throw new \RuntimeException('Uncertain queue enqueue');
        });
        $request = new ServerRequestFactory()->createServerRequest('POST', '/telegram/webhook')
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret');
        $request->getBody()->write($this->json(10, 'hello'));
        $request->getBody()->rewind();
        self::assertSame(503, $this->controller($queue)->webhook($request, new Response())->getStatusCode());
        $this->ingress->handle(Update::fromJson($this->json(11)));
        $target = $this->sql->fetchAssociative('SELECT * FROM telegram_stop_target');
        self::assertTrue($this->stops->isRequested('owner', $target['thread_id'], 'telegram', $target['id']));
    }

    public function testNoticeFailureNeverUndoesStopOrRetargetsRetry(): void
    {
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        $this->failNotice = true;
        self::assertTrue($this->ingress->handle(Update::fromJson($this->json(11))));
        self::assertTrue($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
        self::assertTrue($this->ingress->handle(Update::fromJson($this->json(11))));
        self::assertCount(1, $this->notices);
    }

    public function testSameUpdateCannotBeReboundToDifferentAuthenticatedSender(): void
    {
        $this->ingress->handle(Update::fromJson($this->json(11, '/stop', 43)));
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        $this->runningTurn($target);
        $this->ingress->handle(Update::fromJson($this->json(11)));
        self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
        self::assertCount(1, $this->notices);
    }

    public function testNoActiveTargetIsFrozenEvenIfOlderGenerationAppearsOnRetry(): void
    {
        $this->ingress->handle(Update::fromJson($this->json(11)));
        self::assertStringContainsString('Aucune generation', $this->notices[0]['text']);
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        $this->runningTurn($target);
        $this->ingress->handle(Update::fromJson($this->json(11)));
        self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
        self::assertCount(1, $this->notices);
    }

    public function testForeignBotSenderChatAndTopicCannotStopTarget(): void
    {
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        foreach ([[11, '/stop@OtherBot', 42, -100, 8], [12, '/stop', 43, -100, 8],
            [13, '/stop', 42, -101, 8], [14, '/stop', 42, -100, 9]] as $args) {
            self::assertTrue($this->ingress->handle(Update::fromJson($this->json(...$args))));
            self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
        }
        self::assertTrue($this->ingress->handle(Update::fromJson($this->json(15, '/stop@clairebot'))));
        self::assertTrue($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
    }

    public function testOrdinaryMessagesAndInvalidSecretCannotTriggerStop(): void
    {
        $target = $this->ingress->register('123', 10, 'owner', 'thread', -100, 8);
        self::assertFalse($this->ingress->handle(Update::fromJson($this->json(11, '/stopping'))));
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::never())->method('dispatch');
        $request = new ServerRequestFactory()->createServerRequest('POST', '/telegram/webhook');
        $request->getBody()->write($this->json(11));
        $request->getBody()->rewind();
        self::assertSame(401, $this->controller($queue)->webhook($request, new Response())->getStatusCode());
        self::assertFalse($this->stops->isRequested('owner', 'thread', 'telegram', $target['id']));
        self::assertCount(0, $this->notices);
    }

    private function runningTurn(array $target, array $overrides = []): void
    {
        $this->sql->insert('chat_turn', $overrides + [
            'id' => $target['id'], 'user_id' => $target['user_id'], 'thread_id' => $target['thread_id'],
            'generation_id' => $target['id'], 'channel' => 'telegram', 'status' => 'running',
            'notification' => '{}', 'history_revision' => 0, 'revision' => 0, 'created_at' => 1, 'updated_at' => 1,
        ]);
    }

    private function controller(QueueDispatcherInterface $queue): TelegramController
    {
        $controller = new ReflectionClass(TelegramController::class)->newInstanceWithoutConstructor();
        foreach (['logger' => new NullLogger(), 'settings' => $this->settings, 'queueDispatcher' => $queue,
            'telegramStopIngress' => $this->ingress, 'telegramValidator' => new TelegramValidator(
                new NullLogger(), $this->createStub(EntityManagerInterface::class), $this->settings,
            )] as $name => $value) {
            new ReflectionProperty($controller, $name)->setValue($controller, $value);
        }
        // telegramService deliberately uninitialized: stop may never call manageSession.
        return $controller;
    }

    private function json(int $id, string $text = '/stop', int $sender = 42, int $chat = -100, int $topic = 8): string
    {
        return json_encode(['update_id' => $id, 'message' => ['message_id' => $id, 'date' => 1,
            'from' => ['id' => $sender, 'is_bot' => false, 'first_name' => 'Test'],
            'chat' => ['id' => $chat, 'type' => 'supergroup'], 'message_thread_id' => $topic, 'text' => $text]],
            JSON_THROW_ON_ERROR);
    }
}
