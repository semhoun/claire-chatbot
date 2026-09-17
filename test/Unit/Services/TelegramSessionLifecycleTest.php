<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\Agent;
use App\Brain\BrainAvatar;
use App\Brain\BrainRegistry;
use App\Services\ThemeRegistry;
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
        $this->expectException(\App\Services\Queue\NonRetryableJobException::class);
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
        $service->startNewChat(42, 'direct-test');
        self::assertSame('first', $entity->getSessionData()['brain_avatar']);
        self::assertStringStartsWith(UserChatHistory::CHAT_TELEGRAM, $entity->getSessionData()['threadId']);
    }

    public function testTextAndPhotoDeliveryRetriesBypassAgentAndPhotoDownload(): void
    {
        foreach ([['text' => 'Question'], ['photo' => [[
            'file_id' => 'photo', 'file_unique_id' => 'unique', 'width' => 10, 'height' => 10,
        ]]]] as $content) {
            [$service] = $this->service('Cached answer');
            // No Telegram transport is initialized: accessing it for getFile would fail.
            $update = $this->contentUpdate($content);
            $service->processUpdate($update);
            $service->processUpdate($update);
            self::assertSame(['Cached answer'], $service->sent);
        }
    }

    public function testVoiceDeliveryFailureRetriesCachedAnswerWithoutTranscribing(): void
    {
        [$service, $entity] = $this->service('Cached voice answer');
        $audio = $this->createMock(\App\Services\Audio\AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(true);
        $audio->method('defaultVoice')->willReturn('voice');
        $audio->method('isAllowedVoice')->willReturn(true);
        $audio->expects(self::never())->method('transcribe');
        $audio->expects(self::exactly(2))->method('speech')->with('Cached voice answer', 'voice', 'opus')
            ->willThrowException(new \RuntimeException('Speech delivery unavailable'));
        $transport = $this->createMock(\Phptg\BotApi\Transport\TransportInterface::class);
        $transport->expects(self::exactly(2))->method('post')
            ->with(self::stringEndsWith('/sendChatAction'), self::anything(), self::anything())
            ->willReturn(new \Phptg\BotApi\Transport\ApiResponse(200, '{"ok":true,"result":true}'));
        $api = new \Phptg\BotApi\TelegramBotApi('test', transport: $transport);
        new ReflectionProperty(TelegramService::class, 'audioService')->setValue($service, $audio);
        new ReflectionProperty(TelegramService::class, 'telegramAudioService')->setValue($service,
            new \App\Services\TelegramAudioService($api, $audio, new NullLogger()));
        $update = $this->contentUpdate(['voice' => [
            'file_id' => 'voice', 'file_unique_id' => 'unique', 'duration' => 1,
        ]]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $service->processUpdate($update);
                self::fail('Voice delivery error must propagate');
            } catch (\RuntimeException $error) {
                self::assertSame('Speech delivery unavailable', $error->getMessage());
            }
            if ($attempt === 0) {
                $entity->setSessionData([...$entity->getSessionData(), 'threadId' => 'new-conversation']);
            }
        }
        self::assertSame(['Cached voice answer'], $service->sent);
        self::assertSame('new-conversation', $entity->getSessionData()['threadId']);
        self::assertNull(new ReflectionProperty(TelegramService::class, 'deliveryCheckpoint')->getValue($service));
    }

    public function testConfirmedTextChunksAreNotResentAfterLaterChunkTimeout(): void
    {
        $first = str_repeat('a', 3000);
        $second = str_repeat('b', 2000);
        [$service] = $this->service($first . "\n" . $second);
        $service->useTransport = true;
        $requests = [];
        $transport = $this->createMock(\Phptg\BotApi\Transport\TransportInterface::class);
        $transport->expects(self::exactly(3))->method('post')->willReturnCallback(
            static function (string $url, string $body) use (&$requests): \Phptg\BotApi\Transport\ApiResponse {
                self::assertStringEndsWith('/sendMessage', $url);
                $requests[] = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['text'];
                if (count($requests) === 2) {
                    throw new \RuntimeException('Transport timeout');
                }
                return new \Phptg\BotApi\Transport\ApiResponse(200,
                    '{"ok":true,"result":{"message_id":1,"date":1,"chat":{"id":42,"type":"private"}}}');
            },
        );
        new ReflectionProperty(TelegramService::class, 'telegramBotApi')->setValue($service,
            new \Phptg\BotApi\TelegramBotApi('test', transport: $transport));
        new ReflectionProperty(TelegramService::class, 'telegramMarkdown')->setValue($service,
            new \App\Services\TelegramMarkdown());
        $update = $this->contentUpdate(['text' => 'Question']);
        try {
            $service->processUpdate($update);
            self::fail('Transport timeout must propagate');
        } catch (\RuntimeException $error) {
            self::assertSame('Transport timeout', $error->getMessage());
        }
        $service->processUpdate($update);
        $service->processUpdate($update);
        self::assertSame([$first, $second . "\n", $second . "\n"], $requests);
    }

    private function contentUpdate(array $content): Update
    {
        return Update::fromJson(json_encode([
            'update_id' => 42,
            'message' => ['message_id' => 1, 'date' => 1,
                'chat' => ['id' => 42, 'type' => 'private'],
                'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Test'], ...$content],
        ], JSON_THROW_ON_ERROR));
    }

    public function testMediaPreparationFailuresRemainRetryableBeforeAgentEntry(): void
    {
        foreach (['photo', 'document', 'voice', 'audio'] as $type) {
            $handler = $this->createMock(\NeuronAI\Agent\AgentHandler::class);
            $handler->expects(self::once())->method('events')->willReturnCallback(static function (): \Generator { yield from []; });
            $handler->method('getMessage')->willReturn(new \NeuronAI\Chat\Messages\AssistantMessage('Answer'));
            [$service] = $this->service(handler: $handler);
            $sql = new ReflectionProperty(TelegramService::class, 'entityManager')->getValue($service)->getConnection();
            $journal = new \App\Services\TelegramJournal($sql);
            $turns = new \App\Services\ChatTurnJournal($sql);
            $id = \App\Services\TelegramJournal::id('test', 'update:42');
            $fail = true;
            $preparing = static function () use ($journal, $turns, $id): void {
                self::assertFalse($journal->load($id)['attempted']);
                self::assertNull($turns->get($id));
            };
            $transport = $this->createMock(\Phptg\BotApi\Transport\TransportInterface::class);
            $transport->expects(self::exactly(2))->method('get')->willReturnCallback(
                static function () use ($type, &$fail, $preparing): \Phptg\BotApi\Transport\ApiResponse {
                    $preparing();
                    if ($type === 'photo' && $fail) {
                        $fail = false;
                        throw new \RuntimeException('Preparation unavailable');
                    }
                    return new \Phptg\BotApi\Transport\ApiResponse(200,
                        '{"ok":true,"result":{"file_id":"media","file_unique_id":"unique","file_path":"media.bin"}}');
                });
            $transport->expects(self::exactly($type === 'photo' ? 1 : 2))->method('downloadFile')->willReturnCallback(
                static function () use ($type, &$fail, $preparing) {
                    $preparing();
                    if ($type === 'document' && $fail) {
                        $fail = false;
                        throw new \RuntimeException('Preparation unavailable');
                    }
                    $stream = fopen('php://temp', 'r+b');
                    fwrite($stream, 'media bytes');
                    rewind($stream);
                    return $stream;
                });
            $transport->method('post')->willReturn(new \Phptg\BotApi\Transport\ApiResponse(200, '{"ok":true,"result":true}'));
            $transport->method('postWithFiles')->willReturn(new \Phptg\BotApi\Transport\ApiResponse(200,
                '{"ok":true,"result":{"message_id":1,"date":1,"chat":{"id":42,"type":"private"}}}'));
            $api = new \Phptg\BotApi\TelegramBotApi('test', transport: $transport);
            $audio = $this->createMock(\App\Services\Audio\AudioServiceInterface::class);
            $audio->method('isAvailable')->willReturn(true);
            $audio->method('maxUploadBytes')->willReturn(1000);
            $audio->method('defaultVoice')->willReturn('voice');
            $audio->method('isAllowedVoice')->willReturn(true);
            $audio->method('speech')->willReturn(new \App\Services\Audio\SpeechResult('voice', 'audio/opus', 'opus'));
            $audio->expects(self::exactly(in_array($type, ['audio', 'voice'], true) ? 2 : 0))->method('transcribe')
                ->willReturnCallback(static function () use (&$fail, $preparing): array {
                    $preparing();
                    if ($fail) {
                        $fail = false;
                        throw new \RuntimeException('Preparation unavailable');
                    }
                    return ['text' => 'Prepared question'];
                });
            $settings = new ReflectionProperty(TelegramService::class, 'settings')->getValue($service);
            foreach ([
                'telegramBotApi' => $api, 'audioService' => $audio,
                'telegramAudioService' => new \App\Services\TelegramAudioService($api, $audio, new NullLogger()),
                'telegramChatActionHeartbeat' => new \App\Services\TelegramChatActionHeartbeat(
                    $settings, new NullLogger(), static function (): void {}),
                'filesystem' => $this->createStub(\League\Flysystem\Filesystem::class),
            ] as $property => $value) {
                new ReflectionProperty(TelegramService::class, $property)->setValue($service, $value);
            }
            $media = ['file_id' => 'media', 'file_unique_id' => 'unique', 'duration' => 1,
                'width' => 10, 'height' => 10, 'file_name' => 'media.txt'];
            $update = $this->contentUpdate([$type => $type === 'photo' ? [$media] : $media]);
            try {
                $service->processUpdate($update);
                self::fail('Preparation must fail once');
            } catch (\RuntimeException $error) {
                self::assertSame('Preparation unavailable', $error->getMessage());
                self::assertNotInstanceOf(\App\Services\Queue\NonRetryableJobException::class, $error);
            }
            self::assertFalse($journal->load($id)['attempted']);
            self::assertNull($turns->get($id));
            self::assertSame([], $service->sent);
            $service->processUpdate($update);
            $service->processUpdate($update);
            self::assertSame('succeeded', $turns->get($id)['status']);
            self::assertTrue($journal->load($id)['delivered']);
            self::assertSame(['Answer'], $service->sent);
        }
    }

    /** @return array{LifecycleTelegramService, SessionEntity} */
    private function service(?string $cachedResponse = null, ?\NeuronAI\Agent\AgentHandler $handler = null): array
    {
        $entity = new SessionEntity();
        $entity->setSessionData([Auth::AUTHENTICATED => true, Auth::USERID => 'user-42', 'brain_avatar' => 'first']);
        $repository = $this->createStub(TelegramSessionRepository::class);
        $repository->method('findOrCreateByTelegramId')->willReturn($entity);
        $manager = $this->createStub(\Doctrine\ORM\EntityManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once Settings::getAppRoot() . '/test/Support/TelegramSqlSchema.php';
        \App\Test\Support\TelegramSqlSchema::create($connection);
        require_once Settings::getAppRoot() . '/test/Support/ChatTurnSqlSchema.php';
        \App\Test\Support\ChatTurnSqlSchema::create($connection);
        $manager->method('getConnection')->willReturn($connection);
        $settings = new Settings([
            'llm' => ['brains' => ['first' => LifecycleBrain::class, 'second' => LifecycleBrain::class]],
            'tools' => ['comfyui' => ['enabled' => true]],
            'redis' => ['prefix' => 'test:'],
            'telegram' => ['bot_token' => 'test'],
        ]);
        $history = $this->createStub(UserChatHistory::class);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn (string $class): mixed => match ($class) {
            \NeuronAI\Agent\AgentHandler::class => $handler,
            \Doctrine\DBAL\Connection::class => $connection,
            default => $history,
        });
        $registry = new BrainRegistry($settings, $container, new ThemeRegistry($settings));
        new ReflectionProperty($registry, 'yamlBrainsCache')->setValue($registry, []);
        $workflows = new ComfyUIWorkflowRegistry($settings);
        new ReflectionProperty($workflows, 'cache')->setValue($workflows, [
            'test' => ['label' => 'Test', 'workflow' => 'unused.json'],
        ]);
        $service = new ReflectionClass(LifecycleTelegramService::class)->newInstanceWithoutConstructor();
        if ($cachedResponse !== null) {
            $record = ['userId' => 'user-42', 'threadId' => 'stable-thread', 'botId' => 'test',
                'updateId' => 'update:42', 'attempted' => true, 'response' => $cachedResponse];
            $journal = new \App\Services\TelegramJournal($connection);
            $journal->save(\App\Services\TelegramJournal::id('test', 'update:42'), $record);
        }
        $stateRedis = $this->createStub(\App\Services\RedisClient::class);
        $stateRedis->method('hgetall')->willReturn([]);
        $stateRedis->method('hset')->willReturn(1);
        $generation = new \App\Services\TelegramGeneration($settings, $connection,
            new \App\Services\ChatGenerationState($stateRedis, $settings));
        foreach ([
            'entityManager' => $manager,
            'telegramGeneration' => $generation,
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
    public bool $useTransport = false;

    /** @var list<string> */
    public array $sent = [];

    public function sendMessage(int $telegramChatId, string $text): void
    {
        if ($this->useTransport) {
            parent::sendMessage($telegramChatId, $text);
            return;
        }
        $this->sent[] = $text;
    }
}

final class LifecycleBrain extends Agent implements BrainAvatar
{
    private ChatHistoryInterface $testHistory;

    private ?\NeuronAI\Agent\AgentHandler $testHandler;

    public function __construct(ContainerInterface $container, SessionInterface $session, ?string $threadId = null)
    {
        $connection = $container->get(\Doctrine\DBAL\Connection::class);
        TestCase::assertSame('running', $connection->fetchOne('SELECT status FROM chat_turn WHERE thread_id = ?', [$threadId]));
        $this->testHistory = $container->get(UserChatHistory::class);
        $this->testHandler = $container->get(\NeuronAI\Agent\AgentHandler::class);
    }

    public function stream(
        \NeuronAI\Chat\Messages\Message|array $messages = [],
        ?\NeuronAI\Workflow\Interrupt\InterruptRequest $interrupt = null,
    ): \NeuronAI\Agent\AgentHandler {
        return $this->testHandler;
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
