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
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GenerateAudioJobTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testPublishesRequestedAudioThroughSse(bool $fails): void
    {
        $audioService = $this->createMock(AudioServiceInterface::class);
        $audioService->method('isAvailable')->willReturn(true);
        $audioService->method('isAllowedVoice')->willReturn(true);
        $speech = $audioService->expects(self::exactly(2))
            ->method('speech')
            ->with('Bonjour', 'voice-1', 'mp3');
        if ($fails) {
            $speech->willThrowException(new \RuntimeException('TTS failed'));
        } else {
            $speech->willReturn(new SpeechResult('audio', 'audio/mpeg', 'mp3'));
        }

        $settings = new Settings([
            'redis' => ['prefix' => 'claire:'],
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(2))->method('publish')
            ->with('claire:sse:chat:' . ChatStreamSubscriber::scope('user-1', 'session-1'),
                self::callback(static function (string $message) use ($fails): bool {
                    $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                    return $event['payload']['sessionId'] === 'session-1'
                        && $event['payload']['audioRequestId'] === 'request-stable'
                        && $event['event'] === ($fails ? 'chat.audio.error' : 'chat.audio.ready');
                }))
            ->willReturn(1);
        $redis->method('expire')->willReturn(true);
        $chatAudioPublisher = new ChatAudioPublisher(
            $audioService,
            new ChatStreamPublisher(
                $redis,
                new ChatStreamSubscriber($settings),
                $settings,
            ),
            $this->createStub(LoggerInterface::class),
        );

        $payload = [
            'audioRequestId' => 'request-stable',
            'sessionId' => 'session-1',
            'threadId' => 'thread-1',
            'messageId' => 'message-1',
            'text' => 'Bonjour',
            'session' => [
                \App\Services\Auth::USERID => 'user-1',
                AudioServiceInterface::ENABLED_SESSION_KEY => true,
                AudioServiceInterface::VOICE_SESSION_KEY => 'voice-1',
            ],
        ];
        $job = new GenerateAudioJob($chatAudioPublisher);
        $job->handle($payload);
        $job->handle(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testLegacyAndInvalidJobsFailWithoutGeneratingOrPublishingAudio(): void
    {
        $audio = $this->createMock(AudioServiceInterface::class);
        $audio->expects(self::never())->method('speech');
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::never())->method('publish');
        $settings = new Settings([]);
        $job = new GenerateAudioJob(new ChatAudioPublisher($audio,
            new ChatStreamPublisher($redis, new ChatStreamSubscriber($settings), $settings),
            $this->createStub(LoggerInterface::class)));
        foreach ([[], ['audioRequestId' => ''], ['audioRequestId' => []], ['audioRequestId' => "id\n"]] as $payload) {
            try {
                $job->handle($payload);
                self::fail('Obsolete job was accepted');
            } catch (\App\Services\Queue\NonRetryableJobException $exception) {
                self::assertSame('Obsolete manual audio job: missing or invalid audioRequestId', $exception->getMessage());
            }
        }
    }
}
