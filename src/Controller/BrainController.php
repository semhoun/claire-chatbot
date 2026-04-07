<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Summary;
use App\Queue\QueueDispatcherInterface;
use App\Queue\WebChatMessageJob;
use App\Services\ChatStreamBuffer;
use App\Services\ChatStreamPublisher;
use App\Services\Session\SessionFromRequestTrait;
use App\Services\Session\SessionInterface;
use App\Services\SseEventFormatter;
use App\Services\Settings;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\NonBufferedBody;
use Slim\Views\Twig;

final readonly class BrainController
{
    use SessionFromRequestTrait;

    private const int SSE_KEEPALIVE_INTERVAL = 15;

    public function __construct(
        private Logger $logger,
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private EntityManager $entityManager,
        private Filesystem $filesystem,
        private Settings $settings,
        private QueueDispatcherInterface $queueDispatcher,
        private ChatStreamBuffer $chatStreamBuffer,
        private ChatStreamPublisher $chatStreamPublisher,
        private SseEventFormatter $sseEventFormatter,
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

        $chatId = trim((string) ($data['chatId'] ?? $session->get('chatId') ?? ''));
        if ($chatId === '') {
            return $response->withStatus(400);
        }

        // sessionId is the per-tab SSE binding key (stored in sessionStorage)
        $sessionId = trim((string) ($data['sessionId'] ?? ''));
        if ($sessionId === '') {
            return $response->withStatus(400);
        }

        $messageArticleId = uniqid('assistant-message-', true);
        $attachments = $this->extractAttachments($request, includeStoredFiles: true);

        $session->set('chatId', $chatId);
        $this->queueDispatcher->dispatch(WebChatMessageJob::class, [
            'chatId' => $chatId,
            'sessionId' => $sessionId,
            'messageArticleId' => $messageArticleId,
            'attachments' => $attachments,
            'brainAvatar' => (string) $session->get('brain_avatar'),
            'message' => $userStr,
            'session' => $session->all(),
        ]);

        $response->getBody()->write(json_encode([
            'chatId' => $chatId,
            'messageArticleId' => $messageArticleId,
            'accepted' => true,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
    }

    public function stream(Request $request, Response $response): Response
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $session = $this->getSession($request);
        $queryParams = $request->getQueryParams();

        // sessionId is the per-tab SSE binding key (stable across chat switches within the same tab)
        $sessionId = trim((string) ($queryParams['sessionId'] ?? ''));
        if ($sessionId === '') {
            return $response->withStatus(400);
        }

        // Mode: 'full' (default) for HTMX SSE, 'incremental' for native EventSource
        $mode = (string) ($queryParams['mode'] ?? 'full');

        $chatId = trim((string) ($queryParams['chatId'] ?? $session->get('chatId') ?? ''));
        $messagesHtml = '';
        if ($chatId !== '') {
            $session->set('chatId', $chatId);

            $userChatHistory = new UserChatHistory(
                session: $session,
                pdo: $this->entityManager->getConnection()->getNativeConnection(),
                contextWindow: $this->settings->get('llm.openai.contextWindow')
            );
            $userChatHistory->setThreadId($chatId);
            $userChatHistory->validateMessageSequences();

            $messagesHtml = $this->twig->fetch('partials/messages_list.twig', [
                'messages' => $userChatHistory->getFormattedMessages('stream'),
            ]);
        }

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $stream = $response->getBody();

        // Send initial snapshot for current chat
        if ($mode === 'incremental') {
            $stream->write($this->sseEventFormatter->formatHtmlUpdate('messages', $messagesHtml));
        } else {
            $stream->write($this->sseEventFormatter->formatNamedEvent('chat.snapshot', $messagesHtml));
        }

        $deadline = time() + self::SSE_KEEPALIVE_INTERVAL;
        // Use sessionId as the stream key (per-tab) instead of chatId
        $offset = $this->chatStreamBuffer->length($sessionId);

        while (! connection_aborted()) {
            $messages = $this->chatStreamBuffer->readSince($sessionId, $offset);
            foreach ($messages as $message) {
                $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event)) {
                    $offset++;
                    continue;
                }

                // sessionId is carried in payload for per-tab routing
                $eventSessionId = (string) ($event['payload']['sessionId'] ?? $event['chatId'] ?? '');
                if (! is_array($event) || $eventSessionId !== $sessionId) {
                    $offset++;
                    continue;
                }

                $eventName = (string) ($event['event'] ?? '');
                $payload = $event['payload'] ?? [];
                if ($eventName === '' || ! is_array($payload)) {
                    $offset++;
                    continue;
                }

                // Route events based on mode
                if ($mode === 'incremental') {
                    // Incremental mode: only send granular events as JSON
                    if (in_array($eventName, ['message.assistant.start', 'message.assistant.placeholder', 'message.assistant.delta', 'tool.update', 'message.assistant.done', 'chat.error'], true)) {
                        $incrementalPayload = match ($eventName) {
                            'message.assistant.start' => [
                                'event' => $eventName,
                                'messageId' => $payload['messageId'] ?? null,
                                'messageArticleId' => $payload['messageArticleId'] ?? null,
                            ],
                            'message.assistant.placeholder' => [
                                'html' => [
                                    'messages' => (string) ($payload['html'] ?? ''),
                                ],
                                'mode' => 'append',
                                'event' => $eventName,
                                'messageId' => $payload['messageId'] ?? null,
                                'messageArticleId' => $payload['messageArticleId'] ?? null,
                            ],
                            'message.assistant.delta' => [
                                'html' => [
                                    (string) ($payload['messageId'] ?? 'messages') => (string) ($payload['html'] ?? ''),
                                ],
                                'mode' => 'replace',
                                'event' => $eventName,
                                'messageId' => $payload['messageId'] ?? null,
                                'messageArticleId' => $payload['messageArticleId'] ?? null,
                            ],
                            'tool.update' => [
                                'html' => [
                                    (string) ($payload['toolCallId'] ?? 'messages') => (string) ($payload['html'] ?? ''),
                                ],
                                'mode' => 'replace',
                                'event' => $eventName,
                                'messageId' => $payload['messageId'] ?? null,
                                'messageArticleId' => $payload['messageArticleId'] ?? null,
                                'toolCallId' => $payload['toolCallId'] ?? null,
                            ],
                            'message.assistant.done' => [
                                'event' => $eventName,
                                'messageId' => $payload['messageId'] ?? null,
                                'messageArticleId' => $payload['messageArticleId'] ?? null,
                            ],
                            'chat.error' => [
                                'error' => (string) ($payload['message'] ?? 'Une erreur est survenue.'),
                                'event' => $eventName,
                            ],
                            default => [
                                'event' => $eventName,
                            ],
                        };

                        $eventId = (string) ($payload['messageArticleId'] ?? '');
                        if ($eventId === '') {
                            $eventId = (string) ($payload['messageId'] ?? '');
                        }

                        $stream->write($this->sseEventFormatter->formatJsonEvent(
                            $incrementalPayload,
                            $eventId !== '' ? $eventId : null,
                        ));
                    }

                    // Note: chat.snapshot is NOT sent in incremental mode (HTMX SSE handles it)
                } else {
                    // Full mode (HTMX SSE): send named events for snapshots
                    if ($eventName === 'chat.snapshot') {
                        $htmlContent = $payload['messagesHtml'] ?? '';
                        if ($htmlContent !== '') {
                            $stream->write($this->sseEventFormatter->formatNamedEvent('chat.snapshot', $htmlContent));
                        }
                    }

                    // Note: incremental events are NOT sent in full mode (native EventSource handles them)
                }

                $offset++;
                $deadline = time() + self::SSE_KEEPALIVE_INTERVAL;
            }

            if (time() >= $deadline) {
                $stream->write($this->sseEventFormatter->keepalive());
                $deadline = time() + self::SSE_KEEPALIVE_INTERVAL;
            }

            usleep(250000);
        }

        return $response;
    }

    /**
     * @return array{fileIds: list<string>, uploadedFiles: list<array{filename: string, mimeType: string, content: string}>}
     */
    private function extractAttachments(Request $request, bool $includeStoredFiles): array
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $fileIds = array_values(array_filter(
            array_map(strval(...), (array) ($body['file_ids'] ?? [])),
            static fn (string $fileId): bool => $fileId !== ''
        ));

        $serializedFileIds = [];
        if ($includeStoredFiles) {
            foreach ($fileIds as $fileId) {
                try {
                    $storedAttachment = $this->getStoredFileAttachment($fileId);
                    if ($storedAttachment === null) {
                        continue;
                    }

                    $serializedFileIds[] = $storedAttachment;
                } catch (OptimisticLockException | ORMException | FilesystemException | UnableToReadFile $exception) {
                    $this->logger->error('Failed to extract stored attachment', ['fileId' => $fileId, 'exception' => $exception]);
                }
            }
        }

        $uploadedFiles = [];
        foreach ((array) ($request->getUploadedFiles()['upload_files'] ?? []) as $uploadedFile) {
            if (! $uploadedFile instanceof UploadedFileInterface) {
                continue;
            }

            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            try {
                $stream = $uploadedFile->getStream();
                $stream->rewind();
                $uploadedFiles[] = [
                    'filename' => $uploadedFile->getClientFilename() ?? 'file',
                    'mimeType' => $uploadedFile->getClientMediaType() ?? 'application/octet-stream',
                    'content' => base64_encode((string) $stream->getContents()),
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to extract inline upload', ['error' => $e->getMessage()]);
            }
        }

        return [
            'fileIds' => $includeStoredFiles ? $serializedFileIds : $fileIds,
            'uploadedFiles' => $uploadedFiles,
        ];
    }

    /**
     * @return array{filename: string, mimeType: string, content: string}|null
     */
    private function getStoredFileAttachment(string $fileId): ?array
    {
        $fileDB = $this->entityManager->find(\App\Entity\File::class, $fileId);
        if ($fileDB === null) {
            return null;
        }

        return [
            'filename' => $fileDB->getFilename(),
            'mimeType' => $fileDB->getMimeType(),
            'content' => base64_encode($this->filesystem->read($fileDB->getFileId())),
        ];
    }

    private function buildStoredFileContent(string $fileId): ?FileContent
    {
        $storedAttachment = $this->getStoredFileAttachment($fileId);
        if ($storedAttachment === null) {
            return null;
        }

        return new FileContent(
            $storedAttachment['content'],
            SourceType::BASE64,
            $storedAttachment['mimeType'],
            $storedAttachment['filename'],
        );
    }
}
