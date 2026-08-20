<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Job\Web\GenerateAudioJob;
use App\Job\Web\NewMessageJob;
use App\Renderer\ChatHtmlRenderer;
use App\Services\Audio\AudioServiceInterface;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\CorsHeaders;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use App\Services\SseEventFormatter;
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
use Slim\Psr7\NonBufferedBody;

final readonly class BrainController
{
    use SessionFromRequest;

    public function __construct(
        private Logger $logger,
        private ChatHtmlRenderer $chatHtmlRenderer,
        private BrainRegistry $brainRegistry,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private Settings $settings,
        private AudioServiceInterface $audioService,
        private QueueDispatcherInterface $queueDispatcher,
        private ChatStreamPublisher $chatStreamPublisher,
        private ChatStreamSubscriber $chatStreamSubscriber,
        private SseEventFormatter $sseEventFormatter,
        private CorsHeaders $corsHeaders,
    ) {
    }

    public function submitMessage(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $data = (array) ($request->getParsedBody() ?? []);
        $userStr = trim((string) ($data['message'] ?? ''));
        if ($userStr === '') {
            return $response->withStatus(422);
        }

        $threadId = trim((string) ($data['threadId']));
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

        $messageId = uniqid('assistant-message-', true);
        $attachments = $this->extractAttachments($request, $user, includeStoredFiles: true);

        $this->queueDispatcher->dispatch(
            NewMessageJob::class,
            [
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'messageId' => $messageId,
                'attachments' => $attachments,
                'message' => $userStr,
                'session' => $session->all(),
            ],
            $this->settings->get('queue.defaultQueue')
        );

        $response->getBody()->write(json_encode([
            'threadId' => $threadId,
            'messageId' => $messageId,
            'accepted' => true,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
    }

    public function generateAudio(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        if (! $this->audioService->isAvailable()) {
            return $response->withStatus(503);
        }

        if ($session->get(AudioServiceInterface::ENABLED_SESSION_KEY, false) !== true) {
            return $response->withStatus(409);
        }

        $data = (array) ($request->getParsedBody() ?? []);
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

    public function stream(Request $request, Response $response): Response
    {
        set_time_limit(0);

        $session = $this->getSession($request);
        $queryParams = $request->getQueryParams();

        // sessionId is the per-tab SSE binding key (stable across chat switches within the same tab)
        $sessionId = trim((string) ($queryParams['sessionId'] ?? ''));
        if ($sessionId === '') {
            return $response->withStatus(400);
        }

        $response = $response->withBody(new NonBufferedBody());
        $response = $this->corsHeaders->apply($request, $response)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $stream = $response->getBody();

        $threadId = trim((string) ($queryParams['threadId']));
        if ($threadId !== '') {
            $userChatHistory = new UserChatHistory(
                session: $session,
                pdo: $this->entityManager->getConnection()->getNativeConnection(),
                contextWindow: $this->settings->get('llm.openai.contextWindow'),
                threadId: $threadId,
            );
            $messages = $userChatHistory->getFormattedMessages();
            $messagesHtml = $this->chatHtmlRenderer->messages($messages);
            $stream->write($this->sseEventFormatter->formatJsonEvent([
                'html' => $messagesHtml,
                'threadId' => $threadId,
                'sessionId' => $sessionId,
            ], eventId: 'thread::' . $threadId, eventName: 'chat.snapshot'));
        }

        $stream->write($this->sseEventFormatter->keepalive());

        $onMessage = function (string $message) use ($sessionId, $stream): void {
            if (connection_aborted() !== 0) {
                return;
            }

            $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($event)) {
                return;
            }

            $eventSessionId = (string) ($event['payload']['sessionId']);
            if ($eventSessionId !== $sessionId) {
                return;
            }

            $eventName = (string) ($event['event'] ?? '');
            $payload = $event['payload'] ?? [];
            if ($eventName === '' || ! is_array($payload)) {
                return;
            }

            if (! in_array($eventName, ['chat.snapshot', 'chat.assistant.start', 'chat.assistant.placeholder', 'chat.assistant.update', 'chat.tool.update', 'chat.assistant.done', 'chat.audio.ready', 'chat.audio.error', 'chat.error'], true)) {
                $this->logger->warning('Invalid SSE event', ['event' => $eventName]);
                return;
            }

            $eventId = match ($eventName) {
                'chat.snapshot' => 'thread::' . $payload['threadId'],
                'chat.assistant.start', 'chat.assistant.done' ,
                'chat.assistant.placeholder', 'chat.assistant.update',
                'chat.tool.update', 'chat.audio.ready',
                'chat.audio.error' => 'message::' . $payload['messageId'],
                'chat.error' => 'error:;' . $payload['threadId'],
                default => ''
            };

            $stream->write($this->sseEventFormatter->formatJsonEvent(
                $payload,
                $eventId,
                $eventName,
            ));
        };

        while (connection_aborted() === 0) {
            $message = $this->chatStreamSubscriber->popMessage($sessionId, $this->settings->get('sse.pop_timeout'));
            if ($message !== null) {
                $onMessage($message);
            } else {
                $stream->write($this->sseEventFormatter->keepalive());
            }
        }

        return $response;
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
