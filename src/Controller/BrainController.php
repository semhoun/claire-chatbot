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
use App\Services\ComfyUIService;
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
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\NonBufferedBody;
use Slim\Views\Twig;

final readonly class BrainController
{
    use SessionFromRequestTrait;

    private const string STREAM_STOP = "\n§STREAM-STOP§\n";

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

    /**
     * Processes a chat request, generates a response from the agent, and renders the chat message output.
     *
     * @param Request $request The HTTP request containing the user message and optional attachment data.
     * @param Response $response The HTTP response object to which the output will be appended.
     *
     * @return Response The updated response object containing the rendered chat message.
     */
    public function chat(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'POST') {
            return $this->submitMessage($request, $response);
        }

        set_time_limit((int) $this->settings->get('llm.workflow.timeout'));

        $session = $this->getSession($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) ($request->getParsedBody() ?? []);
            $userStr = trim((string) ($data['message'] ?? ''));
            $chatMode = (string) ($data['mode'] ?? 'stream');
        } else {
            $userStr = trim((string) ($request->getQueryParams()['message'] ?? ''));
            $chatMode = (string) ($request->getQueryParams()['mode'] ?? 'stream');
        }

        if ($userStr === '') {
            // Return a 422 with no body so client can handle error; minimal for now
            return $response->withStatus(422);
        }

        $userMessage = new UserMessage($userStr);
        $userMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
        $userMessage = $this->addAttachments($request, $userMessage);

        // Choisir le cerveau selon la préférence en session
        $currentBrain = $session->get('brain_avatar');
        $agent = $this->brainRegistry->get($currentBrain, $session);

        if ($chatMode === 'chat') {
            // Manage chat mode
            $agentMessage = $agent->chat($userMessage)->getMessage();
            $agentMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
            $agentMessageStr = $agentMessage->getContent();

            $this->manageSummary($session);

            return $this->twig->render($response, 'partials/message.twig', [
                'message' => $agentMessageStr,
                'time' => $agentMessage->getMetadata('timestamp'),
                'sent' => false,
            ]);
        }

        // Some init for htmx
        $streamId = null;
        $toolCallId = null;
        $streamedText = '';
        $toolText = null;
        $streamTimestamp = new \DateTimeImmutable()->format(\DateTimeInterface::ATOM);

        // SSE headers
        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('content-type', 'text/stream')
            ->withHeader('cache-control', 'no-cache');

        $stream = $response->getBody();

        $agentHandler = $agent->stream($userMessage);

        // Iterate chunks
        foreach ($agentHandler->events() as $chunk) {
            if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
                $toolText = '';
                if ($toolCallId === null) {
                    $toolCallId = uniqid('tool-', true);
                }

                if ($chunk instanceof ToolResultChunk) {
                    $toolText = '<span class="tools-done-flag" style="display:none"></span>' . "\n";
                }

                $tool = $chunk->tool;
                $toolText .= "Utilisation de l'outil : " . $tool->getName() . "<br>\n";
                $toolText .= "Paramètres : <br>\n";
                $toolText .= "<ul>\n";
                foreach ($tool->getInputs() as $name => $value) {
                    $toolText .= '<li>' . $name . ' : ' . $value . "</li>\n";
                }

                $toolText .= "</ul>\n";
                if ($chunk instanceof ToolResultChunk) {
                    $toolText .= "Réponse : <br>\n";
                    if ($tool->getResult() !== '' && $tool->getResult() !== '0') {
                        $toolText .= '<pre class="toolcall__result">' . $tool->getResult() . "</pre>\n";
                    }
                }
            } elseif ($chunk instanceof ReasoningChunk) {
                $streamedText .= $chunk->content;
            } elseif ($chunk instanceof TextChunk) {
                $streamedText .= $chunk->content;
            } elseif (is_object($chunk)) {
                $this->logger->error('Unknown chunk type: ' . $chunk::class);
                continue;
            } else {
                continue;
            }

            // On supprime les images car sinon ça fait un résultat bizarre
            $text = preg_replace(ComfyUIService::IMAGE_PATTERN, '', $streamedText);

            if ($streamId === null) {
                $streamId = uniqid('stream-', true);
                $html = $this->twig->fetch('partials/message.twig', [
                    'message' => $text,
                    'time' => $streamTimestamp,
                    'sent' => false,
                    'streamId' => $streamId,
                    'toolCallId' => $toolCallId,
                    'toolCall' => $toolText,
                ]);
                $stream->write($html);
                $stream->write(self::STREAM_STOP);

                continue;
            }

            $html = $this->twig->fetch('partials/md.twig', ['message' => $text]);
            $stream->write('streamId:' . $streamId . "\n" . $html . self::STREAM_STOP);
            if ($toolCallId !== null && $toolText !== null) {
                $stream->write('streamId:' . $toolCallId . "\n" . $toolText . self::STREAM_STOP);
                $toolCallId = null;
            }
        }

        $agentMessage = $agentHandler->getMessage();
        $agentMessage->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));

        $agentMessageStr = $agentMessage->getContent();
        $html = $this->twig->fetch('partials/md.twig', ['message' => $agentMessageStr]);
        $stream->write('streamId:' . $streamId . "\n" . $html . self::STREAM_STOP);

        $this->manageSummary($session);

        return $response;
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

        $session->set('chatId', $chatId);
        $this->queueDispatcher->dispatch(WebChatMessageJob::class, [
            'chatId' => $chatId,
            'brainAvatar' => (string) $session->get('brain_avatar'),
            'message' => $userStr,
            'session' => $session->all(),
        ]);

        $response->getBody()->write((string) json_encode([
            'chatId' => $chatId,
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
        $chatId = trim((string) ($request->getQueryParams()['chatId'] ?? $session->get('chatId') ?? ''));
        if ($chatId === '') {
            return $response->withStatus(400);
        }

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

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $stream = $response->getBody();
        $stream->write($this->sseEventFormatter->format('chat.snapshot', $messagesHtml));
        $stream->write($this->sseEventFormatter->format('chat.snapshot.payload', (string) json_encode([
            'chatId' => $chatId,
            'messagesHtml' => $messagesHtml,
        ], JSON_THROW_ON_ERROR)));
        $stream->write("retry: 1000\n\n");

        $deadline = time() + self::SSE_KEEPALIVE_INTERVAL;
        $offset = $this->chatStreamBuffer->length($chatId);

        while (! connection_aborted()) {
            $messages = $this->chatStreamBuffer->readSince($chatId, $offset);
            foreach ($messages as $message) {
                $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event) || ($event['chatId'] ?? null) !== $chatId) {
                    $offset++;
                    continue;
                }

                $eventName = (string) ($event['event'] ?? '');
                $payload = $event['payload'] ?? [];
                if ($eventName === '' || ! is_array($payload)) {
                    $offset++;
                    continue;
                }

                $payloadData = $eventName === 'chat.snapshot'
                    ? (string) ($payload['messagesHtml'] ?? '')
                    : (string) json_encode($payload, JSON_THROW_ON_ERROR);

                $stream->write($this->sseEventFormatter->format($eventName, $payloadData));
                if ($eventName === 'chat.snapshot') {
                    $stream->write($this->sseEventFormatter->format('chat.snapshot.payload', (string) json_encode($payload, JSON_THROW_ON_ERROR)));
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
     * Manages the generation and persistence of a summary based on chat history.
     *
     * This method retrieves the chat history messages, performs logging for debugging purposes,
     * and triggers the generation and storage of a summary. It is designed to operate
     * under the assumption that chat messages are present, and optimizations should
     * be applied to avoid unnecessary summary generation for empty or unmodified messages.
     */
    private function manageSummary(SessionInterface $session): void
    {
        $summary = new Summary($this->entityManager->getConnection(), $this->settings, $session);
        $messages = $summary->getChatHistory()->getDisplayMessages();
        if ($messages === [] || count($messages) < $this->settings->get('llm.summary.minMessages')) {
            return;
        }

        if (count($messages) > $this->settings->get('llm.summary.maxMessages') && $summary->getChatHistory()->getTitle() !== null) {
            return;
        }

        $summary->generateAndPersist();
    }

    /**
     * Adds attachments from the request to the user message.
     * Processes both uploaded files and files identified by IDs.
     *
     * @param Request $request The HTTP request containing the uploaded files and/or file IDs.
     * @param UserMessage $userMessage The user message to which the attachments will be added.
     *
     * @return UserMessage The updated user message with the added attachments.
     */
    private function addAttachments(Request $request, UserMessage $userMessage): UserMessage
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $fileIds = array_map(strval(...), (array) ($body['file_ids'] ?? []));
        $uploadedFiles = (array) ($request->getUploadedFiles()['upload_files'] ?? []);

        if ($fileIds === [] && $uploadedFiles === []) {
            return $userMessage;
        }

        foreach ($uploadedFiles as $uploadedFile) {
            try {
                if (! method_exists($uploadedFile, 'getError')) {
                    continue;
                }

                if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                    continue;
                }

                $stream = $uploadedFile->getStream();
                $stream->rewind();

                $content = new FileContent(
                    base64_encode((string) $stream->getContents()),
                    SourceType::BASE64,
                    $uploadedFile->getClientMediaType() ?? 'application/octet-stream',
                    $uploadedFile->getClientFilename() ?? 'file'
                );
                $userMessage->addContent($content);
            } catch (\Throwable $e) {
                // best-effort; ignore faulty upload and continue
                $this->logger->warning('Failed to read inline upload', ['error' => $e->getMessage()]);
            }
        }

        foreach ($fileIds as $fileId) {
            try {
                // choose action by mimetype
                $fileDB = $this->entityManager->find(\App\Entity\File::class, $fileId);
                if ($fileDB === null) {
                    continue;
                }

                $content = new FileContent(
                    base64_encode($this->filesystem->read($fileDB->getFileId())),
                    SourceType::BASE64,
                    $fileDB->getMimeType(),
                    $fileDB->getFilename(),
                );
                $userMessage->addContent($content);
            } catch (OptimisticLockException | ORMException | FilesystemException | UnableToReadFile $exception) {
                $this->logger->error('Failed to add addAttachments', ['fileId' => $fileId, 'exception' => $exception]);
            }
        }

        return $userMessage;
    }
}
