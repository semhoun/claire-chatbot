<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\BrainController;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Renderer\ChatDataRenderer;
use App\Repository\ChatHistoryRepository;
use App\Repository\UserRepository;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatSnapshot;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\RedisClient;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BrainControllerTest extends TestCase
{
    public static function audioRequestIds(): array
    {
        return [
            'missing' => [null, 400],
            'empty' => ['', 400],
            'array' => [[], 400],
            'number' => [123, 400],
            'space' => [' request-1', 400],
            'newline' => ["request-1\n", 400],
            'slash' => ['request/1', 400],
            'too long' => [str_repeat('a', 129), 400],
            'uuid' => ['c989387b-b180-4bb2-9169-290c90cc3e93', 202],
            'allowed characters' => ['Az09._-', 202],
            'one character' => ['a', 202],
            'maximum length' => [str_repeat('a', 128), 202],
        ];
    }

    #[DataProvider('audioRequestIds')]
    public function testManualAudioValidatesAndDispatchesExactRequestId(mixed $id, int $status): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $audio = $this->createStub(AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(true);
        [$controller, , $session] = $this->controller($queue, $audio);
        $session->set(AudioServiceInterface::ENABLED_SESSION_KEY, true);
        $queue->expects($status === 202 ? self::once() : self::never())->method('dispatch')
            ->with(\App\Job\Web\GenerateAudioJob::class, self::callback(
                static function (array $payload) use ($id, $session): bool {
                    self::assertSame([
                        'audioRequestId' => $id,
                        'threadId' => 'thread',
                        'sessionId' => 'tab',
                        'messageId' => 'message-1',
                        'text' => 'Bonjour',
                        'session' => $session->all(),
                    ], $payload);
                    return true;
                },
            ), 'default');
        $body = ['threadId' => 'thread', 'sessionId' => 'tab', 'messageId' => 'message-1', 'text' => 'Bonjour'];
        if ($id !== null) {
            $body['audioRequestId'] = $id;
        }
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/audio')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody($body);
        self::assertSame($status, $controller->generateAudio($request, new Response())->getStatusCode());
    }

    public function testSubmissionDelegatesQueuedStateToDispatchAndRejectsSecondGeneration(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        [$controller, $publisher, $session, $pdo] = $this->controller($queue);
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $job, array $payload) use ($publisher, $pdo): string {
                self::assertSame('submission-1', $payload['submissionId']);
                $lock = new \App\Services\ChatThreadLock($pdo, 'user-1', 'thread');
                self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
                $publisher->generationState()->set('user-1', 'thread', $payload['messageId'], 'queued', false);
                self::assertSame(['responding' => true, 'activeMessageId' => $payload['messageId']],
                    $publisher->generationState()->snapshot('user-1', 'thread'));
                $lock->release();
                return 'queue-id';
            },
        );
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                'submissionId' => 'submission-1']);
        $response = $controller->submitMessage($request, new Response());
        self::assertSame(202, $response->getStatusCode());
        $accepted = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('submission-1', $accepted['submissionId']);
        self::assertSame($publisher->generationState()->get('user-1', 'thread')['messageId'], $accepted['messageId']);
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
    }

    public function testDispatchFailureDoesNotCreateGenerationStateAndPropagates(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $failure = new \RuntimeException('Queue unavailable');
        $queue->expects(self::once())->method('dispatch')->willThrowException($failure);
        [$controller, $publisher, $session] = $this->controller($queue);
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                'submissionId' => 'submission-1']);
        try {
            $controller->submitMessage($request, new Response());
            self::fail('Dispatch failure swallowed');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(['responding' => false, 'activeMessageId' => null],
            $publisher->generationState()->snapshot('user-1', 'thread'));
        self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
    }

    public function testAmbiguousDispatchFailureDoesNotOverwriteAtomicallyQueuedGeneration(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        [$controller, $publisher, $session] = $this->controller($queue);
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $job, array $payload) use ($publisher): never {
                $publisher->generationState()->set('user-1', 'thread', $payload['messageId'], 'queued', false);
                throw new \RuntimeException('Reply lost after Redis committed');
            },
        );
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                'submissionId' => 'submission-1']);
        try {
            $controller->submitMessage($request, new Response());
            self::fail('Dispatch failure swallowed');
        } catch (\RuntimeException $exception) {
            self::assertSame('Reply lost after Redis committed', $exception->getMessage());
        }
        self::assertTrue($publisher->generationState()->snapshot('user-1', 'thread')['responding']);
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
    }

    public function testAtomicDispatcherConflictReturns409WithoutChangingState(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::once())->method('dispatch')
            ->willThrowException(new \App\Services\ChatGenerationBusyException('Another producer won'));
        [$controller, $publisher, $session] = $this->controller($queue);
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                'submissionId' => 'submission-1']);
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
        self::assertSame([], $publisher->generationState()->get('user-1', 'thread'));
    }

    public function testReconnectSnapshotUsesAuthenticatedUserAndDoesNotCreateMissingHistory(): void
    {
        [, $publisher, $session, $pdo, , $snapshots] = $this->controller($this->createStub(QueueDispatcherInterface::class));
        $publisher->generationState()->set('user-1', 'thread', 'active', 'queued', false);
        $snapshot = $snapshots->read($session, 'thread');
        self::assertTrue($snapshot['responding']);
        self::assertSame('active', $snapshot['activeMessageId']);
        $otherUser = new InMemorySession([Auth::USERID => 'other-user']);
        $otherSnapshot = $snapshots->read($otherUser, 'thread');
        self::assertFalse($otherSnapshot['responding']);
        self::assertNull($otherSnapshot['activeMessageId']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
    }

    public function testSnapshotRestoresPersistedAudioExpectationAfterReconnect(): void
    {
        [, $publisher, $session, $pdo, , $snapshots] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
        $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Question'));
        $history->addMessage(new \NeuronAI\Chat\Messages\AssistantMessage('Answer'));
        $history->identifyLastAssistantMessage('assistant-snapshot', 'auto-assistant-snapshot');
        $publisher->generationState()->set('user-1', 'thread', 'assistant-snapshot', 'done', true);
        $snapshot = $snapshots->read($session, 'thread');
        self::assertSame(['assistant-snapshot' => 'auto-assistant-snapshot'], $snapshot['audioRequestIds']);
        self::assertFalse($snapshot['responding']);
        self::assertSame(['messageId' => 'assistant-snapshot', 'status' => 'done'], $snapshot['generation']);
        self::assertSame('assistant-snapshot', $snapshot['generationMessageId']);
        self::assertSame('assistant-snapshot', $snapshot['messages'][1]['id']);
        self::assertSame('Answer', $snapshot['messages'][1]['message']);
        self::assertArrayNotHasKey('html', $snapshot);
    }

    public function testSnapshotPreservesPersistedToolGroupIdentityDespiteStaleRunningState(): void
    {
        foreach (['Answer', ''] as $finalContent) {
            [, $publisher, $session, $pdo, , $snapshots] = $this->controller(
                $this->createStub(QueueDispatcherInterface::class),
            );
            $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
            $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Question'));
            $firstTool = new \NeuronAI\Tools\Tool('first')->setCallId('call-1')->setResult('Hidden result');
            $secondTool = new \NeuronAI\Tools\Tool('second')->setCallId('call-2')->setResult('Also hidden');
            $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage('First', [$firstTool]));
            $history->addMessage(new \NeuronAI\Chat\Messages\ToolResultMessage([$firstTool]));
            $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage('second', [$secondTool]));
            $history->addMessage(new \NeuronAI\Chat\Messages\ToolResultMessage([$secondTool]));
            $history->addMessage(new \NeuronAI\Chat\Messages\AssistantMessage($finalContent));
            $history->identifyLastAssistantMessage('assistant-persisted', 'auto-assistant-persisted');

            foreach (['done', 'running'] as $status) {
                $publisher->generationState()->set('user-1', 'thread', 'stale-attempt', $status, true);
                $snapshot = $snapshots->read($session, 'thread');
                self::assertSame('assistant-persisted', $snapshot['messages'][1]['id']);
                self::assertSame('Firstsecond' . $finalContent, $snapshot['messages'][1]['message']);
                self::assertSame(['assistant-persisted' => 'auto-assistant-persisted'], $snapshot['audioRequestIds']);
                self::assertSame(['call-1', 'call-2'], array_column($snapshot['messages'][1]['toolsCall'], 'id'));
            }
        }
    }

    public function testSnapshotRestoresInterruptedToolsAndSafeErrorAfterReconnect(): void
    {
        [, $publisher, $session, $pdo, , $snapshots] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
        $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Generate PDF'));
        $tool = new \NeuronAI\Tools\Tool('generate_pdf', 'Test');
        $tool->setCallId('pdf');
        $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage('Generating', [$tool]));
        $secondTool = new \NeuronAI\Tools\Tool('check_pdf', 'Test')->setCallId('check');
        $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage(' PDF', [$secondTool]));
        foreach (['running', 'error', 'error'] as $status) {
            $publisher->generationState()->set('user-1', 'thread', 'attempt', $status, true);
            $snapshot = $snapshots->read($session, 'thread');
            self::assertSame($status, $snapshot['generationStatus']);
            self::assertSame($status === 'running', $snapshot['responding']);
            self::assertSame('Generating PDF', $snapshot['messages'][1]['message']);
            self::assertCount(2, $snapshot['messages'][1]['toolsCall']);
            if ($status === 'running') {
                self::assertSame('attempt', $snapshot['activeMessageId']);
                self::assertSame('attempt', $snapshot['messages'][1]['id']);
            }
            $renderedTool = $snapshot['messages'][1]['toolsCall'][0];
            self::assertSame($status === 'running', $renderedTool['running']);
            self::assertSame($status !== 'running', $renderedTool['interrupted'] ?? false);
            self::assertNull($renderedTool['result']);
        }
    }

    public function testInvalidSubmissionIdsAreRejectedBeforeDispatch(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::never())->method('dispatch');
        [$controller, , $session] = $this->controller($queue);
        foreach ([null, '', [], 123, '123', ' invalid', "valid\n", 'bad/id', str_repeat('a', 129)] as $id) {
            $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
                ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
                ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                    'submissionId' => $id]);
            self::assertSame(400, $controller->submitMessage($request, new Response())->getStatusCode());
        }
    }

    public function testSqlRunningTurnBlocksAdmissionDespiteRedisError(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::never())->method('dispatch');
        [$controller, $publisher, $session, , , , $connection] = $this->controller($queue);
        new \App\Services\ChatTurnJournal($connection)->begin('old', 'user-1', 'thread', 'web', 'old', 'submission-old');
        $publisher->generationState()->set('user-1', 'thread', 'old', 'error', true);
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                'submissionId' => 'submission-new']);
        self::assertSame(409, $controller->submitMessage($request, new Response())->getStatusCode());
    }

    public function testTurnLookupIsOwnerScopedAndIndependentOfCurrentGeneration(): void
    {
        [$controller, $publisher, $session, , , , $connection] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $journal = new \App\Services\ChatTurnJournal($connection);
        $journal->begin('old', 'user-1', 'thread', 'web', 'old', 'submission-old');
        $journal->begin('private', 'other-user', 'private-thread', 'web', 'private', 'submission-private');
        $publisher->generationState()->set('user-1', 'thread', 'new', 'running', true);
        $request = new ServerRequestFactory()->createServerRequest('GET', '/brain/turn/submission-old')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session);
        foreach (['running', 'rolled_back'] as $status) {
            if ($status === 'rolled_back') {
                $journal->rollback('old', 'user-1');
            }
            $response = $controller->turn($request, new Response(), ['submissionId' => 'submission-old']);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame(['submissionId' => 'submission-old', 'messageId' => 'old', 'threadId' => 'thread',
                'turnStatus' => $status, 'rollbackConfirmed' => $status === 'rolled_back'],
                json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        }
        foreach (['submission-private', 'unknown'] as $id) {
            self::assertSame(404, $controller->turn($request, new Response(), ['submissionId' => $id])->getStatusCode());
        }
        self::assertSame(400, $controller->turn($request, new Response(), ['submissionId' => 'bad/id'])->getStatusCode());
    }

    public function testSubmissionCannotBeReusedAfterEitherTerminalResultAcrossThreads(): void
    {
        foreach (['succeeded', 'rolled_back'] as $status) {
            $queue = $this->createMock(QueueDispatcherInterface::class);
            $queue->expects(self::never())->method('dispatch');
            [$controller, , $session, , , , $sql] = $this->controller($queue);
            $journal = new \App\Services\ChatTurnJournal($sql);
            $journal->begin('original', 'user-1', 'thread', 'web', 'original', 'submission-1');
            if ($status === 'succeeded') {
                $journal->succeed('original', 'user-1');
            } else {
                $journal->rollback('original', 'user-1');
            }
            foreach (['thread', 'other-thread'] as $thread) {
                $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
                    ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
                    ->withParsedBody(['threadId' => $thread, 'sessionId' => 'tab', 'message' => 'Question',
                        'submissionId' => 'submission-1']);
                $response = $controller->submitMessage($request, new Response());
                self::assertSame(409, $response->getStatusCode());
                self::assertSame('', (string) $response->getBody());
            }
            $result = $controller->turn($request, new Response(), ['submissionId' => 'submission-1']);
            $turn = json_decode((string) $result->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('original', $turn['messageId']);
            self::assertSame($status, $turn['turnStatus']);
            self::assertSame(1, (int) $sql->fetchOne('SELECT COUNT(*) FROM chat_turn'));
        }
    }

    public function testSubmissionOfAnotherOwnerDoesNotBlockAdmission(): void
    {
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queue->expects(self::once())->method('dispatch')->willReturn('job');
        [$controller, , $session, , , , $sql] = $this->controller($queue);
        new \App\Services\ChatTurnJournal($sql)->begin(
            'private', 'other-user', 'private-thread', 'web', 'private', 'submission-1',
        );
        $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
            ->withParsedBody(['threadId' => 'thread', 'sessionId' => 'tab', 'message' => 'Question',
                'submissionId' => 'submission-1']);
        self::assertSame(202, $controller->submitMessage($request, new Response())->getStatusCode());
    }

    public function testQueuedSubmissionRedisDuplicateReturns409WithoutNewAcceptedIdentity(): void
    {
        $port = getenv('QUEUE_TEST_REDIS_PORT');
        if ($port === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires ext-redis and isolated QUEUE_TEST_REDIS_PORT');
        }
        $prefix = 'submission-http-test:' . bin2hex(random_bytes(8)) . ':';
        $settings = new Settings(['redis' => ['host' => '127.0.0.1', 'port' => (int) $port,
            'timeout' => 2.0, 'database' => 0, 'password' => null, 'prefix' => $prefix]]);
        $redis = new \Redis();
        $redis->connect('127.0.0.1', (int) $port);
        $queue = $this->createMock(QueueDispatcherInterface::class);
        [$controller, , $session, , , , $sql] = $this->controller($queue);
        $backend = new \App\Services\Queue\RedisQueueBackend(
            new \App\Services\Queue\QueueRedisConnection($settings), $settings, $sql,
        );
        $queue->expects(self::exactly(3))->method('dispatch')->willReturnCallback($backend->dispatch(...));
        try {
            foreach (['thread', 'thread', 'other-thread'] as $index => $thread) {
                $request = new ServerRequestFactory()->createServerRequest('POST', '/brain/messages')
                    ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session)
                    ->withParsedBody(['threadId' => $thread, 'sessionId' => 'tab', 'message' => 'Question',
                        'submissionId' => 'submission-1']);
                $response = $controller->submitMessage($request, new Response());
                self::assertSame($index === 0 ? 202 : 409, $response->getStatusCode());
                if ($index !== 0) {
                    self::assertSame('', (string) $response->getBody());
                }
            }
            self::assertSame(1, $redis->lLen($prefix . 'queue:default'));
            self::assertSame(0, (int) $sql->fetchOne('SELECT COUNT(*) FROM chat_turn'));
        } finally {
            $keys = $redis->keys($prefix . '*');
            if ($keys !== []) {
                $redis->del($keys);
            }
            $redis->close();
        }
    }

    public function testTurnLookupRequiresAuthenticatedUser(): void
    {
        [$controller, , $session] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class), authenticated: false,
        );
        $request = new ServerRequestFactory()->createServerRequest('GET', '/brain/turn/submission-1')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, $session);
        self::assertSame(401, $controller->turn($request, new Response(), ['submissionId' => 'submission-1'])
            ->getStatusCode());
    }

    public function testRolledBackSnapshotContainsPreviousExchangeWithoutFailedTools(): void
    {
        [, $publisher, $session, $pdo, , $snapshots, $connection] = $this->controller(
            $this->createStub(QueueDispatcherInterface::class),
        );
        $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
        $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Previous question')
            ->addMetadata('claire_submission_id', 'submission-previous'));
        $history->addMessage(new \NeuronAI\Chat\Messages\AssistantMessage('Previous answer'));
        $before = $snapshots->read($session, 'thread')['messages'];
        self::assertSame('submission-previous', $before[0]['submissionId']);
        $journal = new \App\Services\ChatTurnJournal($connection);
        $journal->begin('attempt', 'user-1', 'thread', 'web', 'attempt', 'submission-failed');
        $history = new \App\Brain\ChatHistory\UserChatHistory($session, $pdo, threadId: 'thread');
        $history->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Failed question'));
        $history->addMessage(new \NeuronAI\Chat\Messages\ToolCallMessage('Partial', [
            new \NeuronAI\Tools\Tool('incomplete')->setCallId('call'),
        ]));
        $journal->rollback('attempt', 'user-1');
        $publisher->generationState()->set('user-1', 'thread', 'attempt', 'running', true);
        $snapshot = $snapshots->read($session, 'thread');
        self::assertTrue($snapshot['rollbackConfirmed']);
        self::assertSame($before, $snapshot['messages']);
        self::assertSame('submission-failed', $snapshot['submissionId']);
    }

    public function testSnapshotUsesSqlResultEvenWhenRedisProjectionIsStale(): void
    {
        foreach (['running', 'succeeded', 'rolled_back'] as $status) {
            [, $publisher, $session, , , $snapshots, $connection] = $this->controller(
                $this->createStub(QueueDispatcherInterface::class),
            );
            $journal = new \App\Services\ChatTurnJournal($connection);
            $journal->begin('attempt', 'user-1', 'thread', 'web', 'attempt', 'submission-1');
            if ($status === 'succeeded') {
                $journal->succeed('attempt', 'user-1');
            } elseif ($status === 'rolled_back') {
                $journal->rollback('attempt', 'user-1');
            }
            $redisStatus = $status === 'running' ? 'error' : 'running';
            $publisher->generationState()->set('user-1', 'thread', 'attempt', $redisStatus, true);
            $snapshot = $snapshots->read($session, 'thread');
            self::assertSame(['messageId' => 'attempt', 'status' => $redisStatus], $snapshot['generation']);
            self::assertSame('submission-1', $snapshot['submissionId']);
            self::assertSame($status, $snapshot['turnStatus']);
            self::assertSame($status === 'rolled_back', $snapshot['rollbackConfirmed']);
            self::assertSame($status === 'running', $snapshot['responding']);
            self::assertSame([], $snapshot['messages']);
        }
    }

    /** @return array{BrainController, ChatStreamPublisher, InMemorySession, \PDO, RedisClient, ChatSnapshot,
     *     \Doctrine\DBAL\Connection} */
    private function controller(
        QueueDispatcherInterface $queue,
        ?AudioServiceInterface $audio = null,
        bool $authenticated = true,
    ): array
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'llm' => ['openai' => ['contextWindow' => 50000]],
            'queue' => ['defaultQueue' => 'default'],
            'security' => ['cors' => ['allowed_origins' => []]]]);
        $session = new InMemorySession([Auth::USERID => 'user-1']);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pdo = $connection->getNativeConnection();
        $pdo->exec('CREATE TABLE chat_history (user_id TEXT, thread_id TEXT PRIMARY KEY, messages TEXT, '
            . 'display_messages TEXT, display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT, '
            . 'created_at TEXT, updated_at TEXT, revision INTEGER NOT NULL DEFAULT 0, current_turn_id TEXT)');
        $pdo->exec('CREATE TABLE chat_turn (id TEXT PRIMARY KEY, user_id TEXT, thread_id TEXT, channel TEXT, '
            . 'generation_id TEXT, submission_id TEXT, status TEXT, checkpoint TEXT, notification TEXT, '
            . 'history_revision INTEGER, revision INTEGER, created_at INTEGER, updated_at INTEGER, '
            . 'completed_at INTEGER, deleted_at INTEGER, UNIQUE(user_id, submission_id))');
        $user = new User();
        $user->setId('user-1');
        $users = $this->createStub(UserRepository::class);
        $users->method('getCurrentUser')->willReturn($authenticated ? $user : null);
        $histories = $this->createStub(ChatHistoryRepository::class);
        $histories->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class) =>
            $class === User::class ? $users : $histories);
        $redis = $this->createStub(RedisClient::class);
        $storage = [];
        $redis->method('hgetall')->willReturnCallback(static function (string $key) use (&$storage): array {
            return $storage[$key] ?? [];
        });
        $redis->method('hset')->willReturnCallback(static function (string $key, array $value) use (&$storage): int {
            $storage[$key] = array_map(strval(...), $value);
            return 1;
        });
        $subscriber = new ChatStreamSubscriber($settings);
        $publisher = new ChatStreamPublisher($redis, $subscriber, $settings);
        $renderer = new ChatDataRenderer(new GeneratedFileProcessor($settings, $entityManager));
        return [new BrainController(new NullLogger(),
            $entityManager,
            $this->createStub(Filesystem::class), $settings, $audio ?? $this->createStub(AudioServiceInterface::class),
            $queue, $publisher),
            $publisher, $session, $pdo, $redis,
            new ChatSnapshot($publisher->generationState(), $entityManager, $renderer, $settings), $connection];
    }
}
