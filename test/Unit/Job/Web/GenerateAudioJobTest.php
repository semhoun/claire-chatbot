<?php

declare(strict_types=1);

namespace App\Test\Unit\Job\Web;

use App\Job\Web\GenerateAudioJob;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Audio\SpeechResult;
use App\Services\ChatAudioPublisher;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GenerateAudioJobTest extends TestCase
{
    public function testPublishesRequestedAudioThroughSse(): void
    {
        $audioService = $this->createMock(AudioServiceInterface::class);
        $audioService->method('isAvailable')->willReturn(true);
        $audioService->method('isAllowedVoice')->willReturn(true);
        $audioService->expects(self::once())
            ->method('speech')
            ->with('Bonjour', 'voice-1', 'mp3')
            ->willReturn(new SpeechResult('audio', 'audio/mpeg', 'mp3'));

        $settings = new Settings([
            'redis' => ['prefix' => 'claire:'],
            'sse' => ['queue_ttl' => 60],
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::once())->method('rpush');
        $redis->method('expire')->willReturn(true);
        $chatAudioPublisher = new ChatAudioPublisher(
            $audioService,
            new ChatStreamPublisher(
                $redis,
                new ChatStreamSubscriber($redis, $settings),
                $settings,
            ),
            $this->createStub(LoggerInterface::class),
        );

        new GenerateAudioJob($chatAudioPublisher)->handle([
            'sessionId' => 'session-1',
            'threadId' => 'thread-1',
            'messageId' => 'message-1',
            'text' => 'Bonjour',
            'session' => [
                AudioServiceInterface::ENABLED_SESSION_KEY => true,
                AudioServiceInterface::VOICE_SESSION_KEY => 'voice-1',
            ],
        ]);
    }
}
