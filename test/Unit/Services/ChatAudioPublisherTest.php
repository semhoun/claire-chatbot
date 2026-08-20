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
        $redis->expects(self::once())->method('rpush')->with(
            'claire:sse:chat:session-1:queue',
            self::callback(static function (array $messages): bool {
                $event = json_decode((string) $messages[0], true, flags: JSON_THROW_ON_ERROR);

                return $event['event'] === 'chat.audio.ready'
                    && $event['payload']['messageId'] === 'message-1'
                    && $event['payload']['audioData'] === base64_encode('mp3 bytes');
            }),
        );
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
            'session-1',
            'thread-1',
            'message-1',
            '**Bonjour** Claire',
            $inMemorySession,
        );
    }
}
