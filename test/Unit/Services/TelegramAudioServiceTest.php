<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Audio\AudioServiceInterface;
use App\Services\Audio\SpeechResult;
use App\Services\TelegramAudioService;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\TransportInterface;
use Phptg\BotApi\Type\Audio;
use Phptg\BotApi\Type\InputFile;
use Phptg\BotApi\Type\Voice;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class TelegramAudioServiceTest extends TestCase
{
    public function testTranscribesTelegramVoice(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $audioService = $this->createMock(AudioServiceInterface::class);
        $voice = new Voice('voice-id', 'unique-id', 3, 'audio/ogg', 11);

        $this->expectAudioDownload($transport, 'voice-id', 'voice bytes');
        $audioService->method('maxUploadBytes')->willReturn(1_000);
        $audioService->expects(self::once())
            ->method('transcribe')
            ->with('voice bytes', 'audio.ogg', 'audio/ogg')
            ->willReturn(['text' => ' Bonjour Claire ']);

        $telegramAudioService = new TelegramAudioService(
            new TelegramBotApi('token', transport: $transport),
            $audioService,
            $this->createStub(LoggerInterface::class),
        );

        self::assertSame('Bonjour Claire', $telegramAudioService->transcribe($voice));
    }

    public function testTranscribesTelegramAudioWithOriginalFilename(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $audioService = $this->createMock(AudioServiceInterface::class);
        $audio = new Audio(
            'audio-id',
            'unique-id',
            5,
            fileName: 'question.mp3',
            mimeType: 'audio/mpeg',
            fileSize: 15,
        );

        $this->expectAudioDownload($transport, 'audio-id', 'audio bytes');
        $audioService->method('maxUploadBytes')->willReturn(1_000);
        $audioService->expects(self::once())
            ->method('transcribe')
            ->with('audio bytes', 'question.mp3', 'audio/mpeg')
            ->willReturn(['text' => 'Une question']);

        $telegramAudioService = new TelegramAudioService(
            new TelegramBotApi('token', transport: $transport),
            $audioService,
            $this->createStub(LoggerInterface::class),
        );

        self::assertSame('Une question', $telegramAudioService->transcribe($audio));
    }

    public function testRejectsOversizedAudioBeforeDownloadingIt(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('get');
        $transport->expects(self::never())->method('downloadFile');
        $audioService = $this->createStub(AudioServiceInterface::class);
        $audioService->method('maxUploadBytes')->willReturn(10);
        $voice = new Voice('voice-id', 'unique-id', 3, 'audio/ogg', 11);

        $telegramAudioService = new TelegramAudioService(
            new TelegramBotApi('token', transport: $transport),
            $audioService,
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');
        $telegramAudioService->transcribe($voice);
    }

    public function testSendsGeneratedSpeechAsTelegramVoice(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $audioService = $this->createMock(AudioServiceInterface::class);
        $transport->expects(self::once())
            ->method('post')
            ->with(
                self::stringEndsWith('/sendChatAction'),
                self::callback(static function (string $body): bool {
                    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

                    return $data === ['chat_id' => 42, 'action' => 'record_voice'];
                }),
                self::anything(),
            )
            ->willReturn(new ApiResponse(200, '{"ok":true,"result":true}'));
        $audioService->expects(self::once())
            ->method('speech')
            ->with('Bonjour Claire', 'voice-1', 'opus')
            ->willReturn(new SpeechResult('opus bytes', 'audio/opus', 'opus'));
        $transport->expects(self::once())
            ->method('postWithFiles')
            ->with(
                self::stringEndsWith('/sendVoice'),
                self::callback(static fn (array $data): bool => $data['chat_id'] === 42),
                self::callback(static function (array $files): bool {
                    $voice = $files['voice'] ?? null;

                    return $voice instanceof InputFile
                        && $voice->filename() === 'claire.ogg';
                }),
            )
            ->willReturn(new ApiResponse(200, json_encode([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                    'date' => 1_700_000_000,
                    'chat' => ['id' => 42, 'type' => 'private'],
                ],
            ], JSON_THROW_ON_ERROR)));

        $telegramAudioService = new TelegramAudioService(
            new TelegramBotApi('token', transport: $transport),
            $audioService,
            $this->createStub(LoggerInterface::class),
        );

        $telegramAudioService->sendResponse(
            42,
            '**Bonjour** [Claire](https://example.com)',
            'voice-1',
        );
    }

    private function expectAudioDownload(
        TransportInterface&\PHPUnit\Framework\MockObject\MockObject $transport,
        string $fileId,
        string $content,
    ): void {
        $transport->expects(self::once())
            ->method('get')
            ->with(self::stringContains('getFile?file_id=' . $fileId))
            ->willReturn(new ApiResponse(200, json_encode([
                'ok' => true,
                'result' => [
                    'file_id' => $fileId,
                    'file_unique_id' => 'unique-id',
                    'file_size' => strlen($content),
                    'file_path' => 'audio/file.ogg',
                ],
            ], JSON_THROW_ON_ERROR)));
        $transport->expects(self::once())
            ->method('downloadFile')
            ->with(self::stringEndsWith('/file/bottoken/audio/file.ogg'))
            ->willReturnCallback(static function () use ($content) {
                $stream = fopen('php://temp', 'r+b');
                if ($stream === false) {
                    throw new RuntimeException('Unable to create test audio stream');
                }

                fwrite($stream, $content);
                rewind($stream);

                return $stream;
            });
    }
}
