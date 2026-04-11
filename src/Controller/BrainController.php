<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Queue\QueueDispatcherInterface;
use App\Queue\WebChatMessageJob;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\Session\SessionFromRequestTrait;
use App\Services\Settings;
use App\Services\SseEventFormatter;
use Doctrine\ORM\EntityManager;
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
use Slim\Views\Twig;

final readonly class BrainController
{
    use SessionFromRequestTrait;

    public function __construct(
        private Logger $logger,
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private EntityManager $entityManager,
        private Filesystem $filesystem,
        private Settings $settings,
        private QueueDispatcherInterface $queueDispatcher,
        private ChatStreamPublisher $chatStreamPublisher,
        private ChatStreamSubscriber $chatStreamSubscriber,
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

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $stream = $response->getBody();

        $chatId = trim((string) ($queryParams['chatId'] ?? $session->get('chatId') ?? ''));
        if ($chatId !== '') {
            $session->set('chatId', $chatId);

            $userChatHistory = new UserChatHistory(
                session: $session,
                pdo: $this->entityManager->getConnection()->getNativeConnection(),
                contextWindow: $this->settings->get('llm.openai.contextWindow')
            );
            $messagesHtml = $this->twig->fetch('partials/messages_list.twig', [
                'messages' => $userChatHistory->getFormattedMessages(),
            ]);

            $stream->write($this->sseEventFormatter->formatJsonEvent([
                'html' => [
                    'messages' => $messagesHtml,
                ],
                'mode' => 'replace',
            ], eventId: $chatId, eventName: 'chat.snapshot'));
        }

        $stream->write($this->sseEventFormatter->keepalive());

        $this->chatStreamSubscriber->subscribe($sessionId, function (string $message) use ($sessionId, $stream): void {
            if (connection_aborted()) {
                return;
            }

            $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($event)) {
                return;
            }

            $eventSessionId = (string) ($event['payload']['sessionId'] ?? $event['chatId'] ?? '');
            if ($eventSessionId !== $sessionId) {
                return;
            }

            $eventName = (string) ($event['event'] ?? '');
            $payload = $event['payload'] ?? [];
            if ($eventName === '' || ! is_array($payload)) {
                return;
            }

            if (! in_array($eventName, ['chat.snapshot', 'message.assistant.start', 'message.assistant.placeholder', 'message.assistant.delta', 'tool.update', 'message.assistant.done', 'chat.error'], true)) {
                return;
            }

            $streamPayload = match ($eventName) {
                'chat.snapshot' => [
                    'html' => [
                        'messages' => (string) ($payload['messagesHtml'] ?? ''),
                    ],
                    'mode' => 'replace',
                ],
                'message.assistant.start' => [
                    'messageId' => $payload['messageId'] ?? null,
                    'messageArticleId' => $payload['messageArticleId'] ?? null,
                ],
                'message.assistant.placeholder' => [
                    'html' => [
                        'messages' => (string) ($payload['html'] ?? ''),
                    ],
                    'mode' => 'append',
                    'messageId' => $payload['messageId'] ?? null,
                    'messageArticleId' => $payload['messageArticleId'] ?? null,
                ],
                'message.assistant.delta' => [
                    'html' => [
                        (string) ($payload['messageId'] ?? 'messages') => (string) ($payload['html'] ?? ''),
                    ],
                    'mode' => 'replace',
                    'messageId' => $payload['messageId'] ?? null,
                    'messageArticleId' => $payload['messageArticleId'] ?? null,
                ],
                'tool.update' => [
                    'html' => [
                        (string) ($payload['toolCallId'] ?? 'messages') => (string) ($payload['html'] ?? ''),
                    ],
                    'mode' => 'replace',
                    'messageId' => $payload['messageId'] ?? null,
                    'messageArticleId' => $payload['messageArticleId'] ?? null,
                    'toolCallId' => $payload['toolCallId'] ?? null,
                ],
                'message.assistant.done' => [
                    'messageId' => $payload['messageId'] ?? null,
                    'messageArticleId' => $payload['messageArticleId'] ?? null,
                ],
                'chat.error' => [
                    'error' => (string) ($payload['message'] ?? 'Une erreur est survenue.'),
                ],
                default => [],
            };

            $eventId = (string) ($payload['messageArticleId'] ?? '');
            if ($eventId === '') {
                $eventId = (string) ($payload['messageId'] ?? '');
            }

            $stream->write($this->sseEventFormatter->formatJsonEvent(
                $streamPayload,
                $eventId !== '' ? $eventId : null,
                $eventName,
            ));
        });

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
                    'content' => base64_encode($stream->getContents()),
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
}
