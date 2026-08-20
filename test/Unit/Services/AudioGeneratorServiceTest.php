<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ChatHistoryRepository;
use App\Repository\UserRepository;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Audio\SpeechResult;
use App\Services\AudioGeneratorService;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class AudioGeneratorServiceTest extends TestCase
{
    public function testGeneratesAndPersistsAnMp3File(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $user = new User();
        $user->setId('user-1');

        $chatHistory = new ChatHistory();
        $chatHistory->setUser($user);
        $chatHistory->setThreadId('thread-1');

        $audioService = $this->createMock(AudioServiceInterface::class);
        $audioService->method('isAvailable')->willReturn(true);
        $audioService->method('defaultVoice')->willReturn('voice-1');
        $audioService->method('isAllowedVoice')->with('voice-1')->willReturn(true);
        $audioService->method('speechModel')->willReturn('tts-model');
        $audioService->expects(self::once())->method('speech')
            ->with('Bonjour', 'voice-1', 'mp3')
            ->willReturn(new SpeechResult('mp3-content', 'audio/mpeg', 'mp3'));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('getCurrentUser')->with($session)->willReturn($user);
        $historyRepository = $this->createMock(ChatHistoryRepository::class);
        $historyRepository->method('getCurrentUserChatHistory')
            ->with($session, 'thread-1')
            ->willReturn($chatHistory);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): UserRepository|ChatHistoryRepository => match ($class) {
                User::class => $userRepository,
                ChatHistory::class => $historyRepository,
            },
        );
        $entityManager->expects(self::once())->method('persist')->with(
            self::callback(static fn (File $file): bool =>
                $file->fileType() === File::FILE_TYPE_AUDIO
                && $file->getFilename() === 'lecture.mp3'
                && $file->getMetadata() === [
                    'voice' => 'voice-1',
                    'model' => 'tts-model',
                ]),
        );
        $entityManager->expects(self::once())->method('flush');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('write')
            ->with(self::stringContains('.mp3'), 'mp3-content');

        $fileId = new AudioGeneratorService(
            $audioService,
            $filesystem,
            $entityManager,
        )->generate($session, 'thread-1', 'Bonjour', filename: 'lecture');

        self::assertMatchesRegularExpression(
            '/^@@GENERATED@@[a-f0-9-]+@@$/',
            $fileId,
        );
    }
}
