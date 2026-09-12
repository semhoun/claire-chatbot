<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Audio\AudioServiceInterface;
use App\Services\Audio\SpeechResult;
use App\Services\ChatAudioPublisher;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ChatAudioPublisherTest extends TestCase
{
    public function testPublishesGeneratedAudioOnTheChatStream(): void
    {
        $audioService = $this->createMock(AudioServiceInterface::class);
        $audioService->method('isAvailable')->willReturn(true);
        $audioService->method('isAllowedVoice')->with('fr_marie_neutral')->willReturn(true);
        $audioService->expects(self::once())
            ->method('speech')
            ->with('Bonjour Claire', 'fr_marie_neutral', 'mp3')
            ->willReturn(new SpeechResult('mp3 bytes', 'audio/mpeg', 'mp3'));

        $settings = new Settings([
            'redis' => ['prefix' => 'claire:'],
            'sse' => ['queue_ttl' => 60],
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::once())->method('lpush')->with(
            'claire:sse:chat:' . ChatStreamSubscriber::scope('user-1', 'session-1') . ':queue',
            self::callback(static function (array $messages): bool {
                $event = json_decode((string) $messages[0], true, flags: JSON_THROW_ON_ERROR);

                return $event['event'] === 'chat.audio.ready'
                    && $event['payload']['messageId'] === 'message-1'
                    && $event['payload']['audioRequestId'] === 'request-1'
                    && $event['payload']['audioData'] === base64_encode('mp3 bytes');
            }),
        )->willReturn(1);
        $redis->method('expire')->willReturn(true);
        $chatStreamPublisher = new ChatStreamPublisher(
            $redis,
            new ChatStreamSubscriber($redis, $settings),
            $settings,
        );
        $chatAudioPublisher = new ChatAudioPublisher(
            $audioService,
            $chatStreamPublisher,
            $this->createStub(LoggerInterface::class),
        );
        $inMemorySession = new InMemorySession([
            AudioServiceInterface::ENABLED_SESSION_KEY => true,
            AudioServiceInterface::VOICE_SESSION_KEY => 'fr_marie_neutral',
        ]);

        $chatAudioPublisher->publish(
            ChatStreamSubscriber::scope('user-1', 'session-1'),
            'thread-1',
            'message-1',
            '**Bonjour** Claire',
            $inMemorySession,
            'request-1',
        );
    }

    public function testErrorPreservesTheRequestIdForEachAttempt(): void
    {
        $audio = $this->createMock(AudioServiceInterface::class);
        $audio->method('isAvailable')->willReturn(true);
        $audio->expects(self::exactly(2))->method('speech')->willThrowException(new \RuntimeException('TTS failed'));
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'sse' => ['queue_ttl' => 60]]);
        $events = [];
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(2))->method('lpush')->willReturnCallback(
            static function (string $key, array $messages) use (&$events): int {
                $events[] = json_decode($messages[0], true, flags: JSON_THROW_ON_ERROR);
                return 1;
            },
        );
        $redis->method('expire')->willReturn(true);
        $publisher = new ChatAudioPublisher($audio,
            new ChatStreamPublisher($redis, new ChatStreamSubscriber($redis, $settings), $settings),
            $this->createStub(LoggerInterface::class));
        $session = new InMemorySession([AudioServiceInterface::ENABLED_SESSION_KEY => true]);
        foreach (['request-old', 'request-new'] as $id) {
            $publisher->publish(ChatStreamSubscriber::scope('user-1', 'session-1'),
                'thread-1', 'message-1', 'Bonjour', $session, $id);
        }
        self::assertSame(['chat.audio.error', 'chat.audio.error'], array_column($events, 'event'));
        self::assertSame(['request-old', 'request-new'], array_column(array_column($events, 'payload'), 'audioRequestId'));
    }
}
