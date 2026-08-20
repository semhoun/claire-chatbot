<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use RuntimeException;

final readonly class AudioGeneratorService
{
    private const int MAX_TEXT_LENGTH = 4096;

    public function __construct(
        private AudioServiceInterface $audioService,
        private Filesystem $filesystem,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function generate(
        SessionInterface $session,
        string $threadId,
        string $text,
        ?string $voice = null,
        ?string $filename = null,
    ): string {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Audio text cannot be empty');
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new RuntimeException('Audio text exceeds 4096 characters');
        }

        if (! $this->audioService->isAvailable()) {
            throw new RuntimeException('Audio generation is not available');
        }

        $resolvedVoice = $voice === null || trim($voice) === ''
            ? $this->audioService->defaultVoice()
            : trim($voice);
        if (! $this->audioService->isAllowedVoice($resolvedVoice)) {
            throw new RuntimeException('Unknown audio voice');
        }

        $user = $this->entityManager->getRepository(User::class)
            ->getCurrentUser($session);
        if ($user === null) {
            throw new RuntimeException('User not found for audio generation');
        }

        $history = $this->entityManager->getRepository(ChatHistory::class)
            ->getCurrentUserChatHistory($session, $threadId);
        if ($history === null) {
            throw new RuntimeException(
                'Cannot generate audio for non-existent chat history',
            );
        }

        $speech = $this->audioService->speech($text, $resolvedVoice, 'mp3');
        $file = new File();
        $file->setGeneratedFileData(
            $history,
            $filename ?? 'synthese-vocale',
            'mp3',
            strlen($speech->content),
            [
                'voice' => $resolvedVoice,
                'model' => $this->audioService->speechModel(),
            ],
        );

        try {
            $this->filesystem->write($file->getFilePath(), $speech->content);
        } catch (FilesystemException $filesystemException) {
            throw new RuntimeException(
                'Failed to save generated audio',
                (int) $filesystemException->getCode(),
                $filesystemException,
            );
        }

        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return $file->getFileId();
    }
}
