<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\ChatHistory\UserChatHistory;
use App\Job\Web\GenerateAudioJob;
use App\Job\Web\NewMessageJob;
use App\Services\Audio\AudioServiceInterface;
use App\Services\ChatGenerationBusyException;
use App\Services\ChatStreamPublisher;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface as Logger;

final readonly class BrainController
{
    use SessionFromRequest;

    public function __construct(
        private Logger $logger,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private Settings $settings,
        private AudioServiceInterface $audioService,
        private QueueDispatcherInterface $queueDispatcher,
        private ChatStreamPublisher $chatStreamPublisher,
    ) {
    }

    public function submitMessage(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $data = (array) ($request->getParsedBody() ?? []);
        $submissionId = $data['submissionId'] ?? null;
        if (! is_string($submissionId)
            || preg_match(UserChatHistory::MESSAGE_ID_PATTERN, $submissionId) !== 1) {
            return $response->withStatus(400);
        }
        $userStr = trim((string) ($data['message'] ?? ''));
        if ($userStr === '') {
            return $response->withStatus(422);
        }

        $threadId = trim((string) ($data['threadId'] ?? ''));
        if ($threadId === '') {
            return $response->withStatus(400);
        }

        // sessionId is the per-tab SSE binding key (stored in sessionStorage)
        $sessionId = trim((string) ($data['sessionId'] ?? ''));
        if ($sessionId === '') {
            return $response->withStatus(400);
        }

        $user = $this->entityManager->getRepository(\App\Entity\User::class)->getCurrentUser($session);
        if ($user === null) {
            return $response->withStatus(401);
        }

        // BOLA check: if thread exists, it must belong to the current user
        $existingHistory = $this->entityManager->getRepository(\App\Entity\ChatHistory::class)->findOneBy(['threadId' => $threadId]);
        if ($existingHistory !== null && $existingHistory->getUser()->getId() !== $user->getId()) {
            return $response->withStatus(403);
        }

        $userId = (string) $user->getId();
        if ($this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM chat_turn WHERE user_id = ?'
                . ' AND (submission_id = ? OR (thread_id = ? AND status = ?))',
            [$userId, $submissionId, $threadId, 'running'],
        ) !== false) {
            return $response->withStatus(409);
        }

        $messageId = uniqid('assistant-message-', true);
        $attachments = $this->extractAttachments($request, $user, includeStoredFiles: true);
        $chatGenerationState = $this->chatStreamPublisher->generationState();
        $previous = $chatGenerationState->get($userId, $threadId);
        if (in_array($previous['status'] ?? '', ['queued', 'running', 'deleted'], true)) {
            return $response->withStatus(409);
        }

        try {
            $this->queueDispatcher->dispatch(
                NewMessageJob::class,
                [
                    'threadId' => $threadId,
                    'sessionId' => $sessionId,
                    'messageId' => $messageId,
                    'submissionId' => $submissionId,
                    'attachments' => $attachments,
                    'message' => $userStr,
                    'session' => $session->all(),
                ],
                $this->settings->get('queue.defaultQueue')
            );
        } catch (ChatGenerationBusyException) {
            return $response->withStatus(409);
        }

        $response->getBody()->write(json_encode([
            'threadId' => $threadId,
            'messageId' => $messageId,
            'submissionId' => $submissionId,
            'accepted' => true,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, string> $args */
    public function turn(Request $request, Response $response, array $args): Response
    {
        $response = $response->withHeader('Cache-Control', 'no-store');
        $user = $this->entityManager->getRepository(\App\Entity\User::class)
            ->getCurrentUser($this->getSession($request));
        if ($user === null) {
            return $response->withStatus(401);
        }
        $submissionId = $args['submissionId'] ?? null;
        if (! is_string($submissionId)
            || preg_match(UserChatHistory::MESSAGE_ID_PATTERN, $submissionId) !== 1) {
            return $response->withStatus(400);
        }
        $turn = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT submission_id, generation_id, thread_id, status FROM chat_turn'
                . ' WHERE user_id = ? AND submission_id = ?',
            [(string) $user->getId(), $submissionId],
        );
        if ($turn === false) {
            return $response->withStatus(404);
        }
        $response->getBody()->write(json_encode([
            'submissionId' => $turn['submission_id'],
            'messageId' => $turn['generation_id'],
            'threadId' => $turn['thread_id'],
            'turnStatus' => $turn['status'],
            'rollbackConfirmed' => $turn['status'] === 'rolled_back',
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function generateAudio(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $data = (array) ($request->getParsedBody() ?? []);
        $audioRequestId = $data['audioRequestId'] ?? null;
        if (! is_string($audioRequestId)
            || preg_match(UserChatHistory::AUDIO_REQUEST_ID_PATTERN, $audioRequestId) !== 1) {
            return $response->withStatus(400);
        }

        if (! $this->audioService->isAvailable()) {
            return $response->withStatus(503);
        }

        if ($session->get(AudioServiceInterface::ENABLED_SESSION_KEY, false) !== true) {
            return $response->withStatus(409);
        }

        $threadId = trim((string) ($data['threadId'] ?? ''));
        $sessionId = trim((string) ($data['sessionId'] ?? ''));
        $messageId = trim((string) ($data['messageId'] ?? ''));
        $text = trim((string) ($data['text'] ?? ''));
        if ($threadId === '' || $sessionId === '' || $messageId === '') {
            return $response->withStatus(400);
        }

        if ($text === '' || mb_strlen($text) > 4096) {
            return $response->withStatus(422);
        }

        $user = $this->entityManager->getRepository(\App\Entity\User::class)
            ->getCurrentUser($session);
        if ($user === null) {
            return $response->withStatus(401);
        }

        $history = $this->entityManager->getRepository(\App\Entity\ChatHistory::class)
            ->findOneBy(['threadId' => $threadId]);
        if ($history !== null && $history->getUser()->getId() !== $user->getId()) {
            return $response->withStatus(403);
        }

        $this->queueDispatcher->dispatch(
            GenerateAudioJob::class,
            [
                'audioRequestId' => $audioRequestId,
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'messageId' => $messageId,
                'text' => $text,
                'session' => $session->all(),
            ],
            $this->settings->get('queue.defaultQueue'),
        );

        return $response->withStatus(202);
    }

    /**
     * @return array{fileIds: list<string>, uploadedFiles: list<array{filename: string, mimeType: string, content: string}>}
     */
    private function extractAttachments(Request $request, \App\Entity\User $user, bool $includeStoredFiles): array
    {
        $fileIds = $this->extractFileIdsFromRequest($request);

        return [
            'fileIds' => $includeStoredFiles ? $this->serializeStoredFileIds($fileIds, $user) : $fileIds,
            'uploadedFiles' => $this->extractUploadedFiles($request),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractFileIdsFromRequest(Request $request): array
    {
        $body = (array) ($request->getParsedBody() ?? []);

        return array_values(array_filter(
            array_map(strval(...), (array) ($body['file_ids'] ?? [])),
            static fn (string $fileId): bool => $fileId !== ''
        ));
    }

    /**
     * @param list<string> $fileIds
     *
     * @return list<array{filename: string, mimeType: string, content: string}>
     */
    private function serializeStoredFileIds(array $fileIds, \App\Entity\User $user): array
    {
        $serializedFileIds = [];

        foreach ($fileIds as $fileId) {
            try {
                $storedAttachment = $this->getStoredFileAttachment($fileId, $user);
                if ($storedAttachment !== null) {
                    $serializedFileIds[] = $storedAttachment;
                }
            } catch (OptimisticLockException | ORMException | FilesystemException | UnableToReadFile $exception) {
                $this->logger->error('Failed to extract stored attachment', ['fileId' => $fileId, 'exception' => $exception]);
            }
        }

        return $serializedFileIds;
    }

    /**
     * @return list<array{filename: string, mimeType: string, content: string}>
     */
    private function extractUploadedFiles(Request $request): array
    {
        $uploadedFiles = [];

        foreach ((array) ($request->getUploadedFiles()['upload_files'] ?? []) as $uploadedFile) {
            $fileData = $this->processUploadedFile($uploadedFile);
            if ($fileData !== null) {
                $uploadedFiles[] = $fileData;
            }
        }

        return $uploadedFiles;
    }

    /**
     * @return array{filename: string, mimeType: string, content: string}|null
     */
    private function processUploadedFile(mixed $uploadedFile): ?array
    {
        if (! $uploadedFile instanceof UploadedFileInterface) {
            return null;
        }

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        try {
            $stream = $uploadedFile->getStream();
            $stream->rewind();

            return [
                'filename' => $uploadedFile->getClientFilename() ?? 'file',
                'mimeType' => $uploadedFile->getClientMediaType() ?? 'application/octet-stream',
                'content' => base64_encode($stream->getContents()),
            ];
        } catch (\Throwable $throwable) {
            $this->logger->warning('Failed to extract inline upload', ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{filename: string, mimeType: string, content: string}|null
     */
    private function getStoredFileAttachment(string $fileId, \App\Entity\User $user): ?array
    {
        $fileDB = $this->entityManager->getRepository(\App\Entity\File::class)->findOneBy([
            'fileId' => $fileId,
            'user' => $user,
        ]);

        if ($fileDB === null) {
            return null;
        }

        return [
            'filename' => $fileDB->getFilename(),
            'mimeType' => $fileDB->getMimeType(),
            'content' => base64_encode($this->filesystem->read($fileDB->getFilePath())),
        ];
    }
}
