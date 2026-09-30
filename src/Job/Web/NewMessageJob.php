<?php

declare(strict_types=1);

namespace App\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Summary;
use App\Renderer\ChatDataRenderer;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ChatAudioPublisher;
use App\Services\ChatStopRequests;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatThreadLock;
use App\Services\ChatTurnJournal;
use App\Services\ChatTurnRecovery;
use App\Services\GenerationExecution;
use App\Services\GenerationStopToken;
use App\Services\Queue\QueueDoer;
use App\Services\SemanticMemoryRegistry;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;

/**
 * Handles the processing of streaming chat messages in a web-based real-time chat system.
 *
 * This class is utilized to process user messages, handle chat state, stream agent responses,
 * manage message attachments, and publish updates to a chat stream through a publisher service.
 *
 * Responsibilities include:
 * - Initializing the context for chat processing with user, session, and message data.
 * - Streaming agent responses to the user while managing asynchronous chunks of text or tools.
 * - Handling formatting and publishing of updates for tool usage and user-facing text chunks.
 * - Managing error states and ensuring appropriate feedback is provided to the user when issues occur.
 * - Finalizing the chat stream once all processing is complete.
 *
 * Implements the `QueueDoer` interface to integrate with a queue job execution system.
 */
final class NewMessageJob implements QueueDoer
{
    private string $streamedText = '';

    /** @var array<string, string> */
    private array $streamedMessages = [];

    private int $nbPublishedChunks = 0;

    private string $userMessage = '';

    private string $threadId = '';

    private string $sessionId = '';

    private string $messageId = '';

    private ?string $submissionId = null;

    private string $turnStatus = 'running';

    private ?string $autoAudioRequestId = null;

    private string $userId = '';

    private InMemorySession $inMemorySession;

    private ?Agent $agent = null;

    private ?GenerationStopToken $stopToken = null;

    /** @var list<\NeuronAI\Chat\Messages\Message> */
    private array $turnMessages = [];

    private bool $streamStopped = false;

    private string $finalAssistantText = '';

    /** @var array<int, string>|null */
    private ?array $attachments = null;

    /** @var array<string, array<string, mixed>> */
    private array $toolsCall = [];

    public function __construct(
        private readonly Logger $logger,
        private readonly ChatDataRenderer $chatDataRenderer,
        private readonly BrainRegistry $brainRegistry,
        private readonly ChatStreamPublisher $chatStreamPublisher,
        private readonly ChatAudioPublisher $chatAudioPublisher,
        private readonly Connection $connection,
        private readonly Settings $settings,
        private readonly ?SemanticMemoryRegistry $semanticMemoryRegistry = null,
        private readonly ?\App\Services\Queue\QueueDispatcherInterface $semanticQueue = null,
    ) {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $this->streamedText = '';
        $this->streamedMessages = [];
        $this->toolsCall = [];
        $this->nbPublishedChunks = 0;
        $this->attachments = null;
        $this->agent = null;
        $this->stopToken = null;
        $this->turnMessages = [];
        $this->streamStopped = false;
        $this->finalAssistantText = '';
        $this->userMessage = '';
        $this->threadId = '';
        $this->sessionId = '';
        $this->messageId = '';
        $this->autoAudioRequestId = null;
        $this->userId = '';
        unset($this->inMemorySession);
        $this->initContext($payload);
        $this->turnStatus = 'running';
        $chatThreadLock = new ChatThreadLock($this->connection->getNativeConnection(), $this->userId, $this->threadId);
        $acceptedStatus = $this->settings->get('llm.stop.enabled', false) === true
            ? $this->connection->fetchOne(
                'SELECT status FROM chat_stop_request'
                . ' WHERE user_id = ? AND thread_id = ? AND channel = ? AND generation_id = ?',
                [$this->userId, $this->threadId, 'web', $this->messageId],
            ) : false;
        $stops = $acceptedStatus !== false ? new ChatStopRequests($this->connection) : null;
        $journal = new ChatTurnJournal($this->connection, $stops);
        $turn = $journal->get($this->messageId);
        if ($turn !== null) {
            if ($turn['userId'] !== $this->userId || $turn['threadId'] !== $this->threadId) {
                throw new \App\Services\Queue\NonRetryableJobException('Chat turn identity mismatch');
            }
            if (($turn['deletedAt'] ?? null) !== null) {
                return;
            }
            $turn = $journal->rollback($this->messageId, $this->userId);
            $this->turnStatus = $turn['status'];
            $state = $this->chatStreamPublisher->generationState();
            try {
                $previous = $state->get($this->userId, $this->threadId);
                if (! ChatTurnRecovery::canProjectTerminal($this->connection, $turn, $previous)) {
                    return;
                }
                $state->set(
                    $this->userId,
                    $this->threadId,
                    $this->messageId,
                    match ($this->turnStatus) {
                        'succeeded' => 'done', 'stopped' => 'stopped', default => 'error',
                    },
                    true,
                );
            } catch (\Throwable $projectionError) {
                if ($this->turnStatus !== 'stopped') {
                    throw $projectionError;
                }
                $this->logger->warning('Cannot project stopped retry', ['exception' => $projectionError]);
                return;
            }
            if ($this->turnStatus === 'rolled_back') {
                $this->handleChatError(new \RuntimeException('Interrupted chat attempt'));
            }
            return;
        }
        if ($acceptedStatus !== false && $acceptedStatus !== 'accepted') {
            // An acceptance terminalized before dispatch cannot authorize a fresh worker attempt.
            return;
        }
        $chatGenerationState = $this->chatStreamPublisher->generationState();
        if ($stops !== null) {
            $userId = $this->userId;
            $threadId = $this->threadId;
            $generationId = $this->messageId;
            $this->stopToken = new GenerationStopToken(
                static fn (): bool => $stops->isRequested($userId, $threadId, 'web', $generationId),
            );
        }
        $preStopped = $this->stopToken?->isRequested() ?? false;
        try {
            $previous = $chatGenerationState->get($this->userId, $this->threadId);
        } catch (\Throwable $projectionError) {
            if (! $preStopped) {
                throw $projectionError;
            }
            $previous = [];
        }
        if (($previous['status'] ?? '') === 'deleted'
            || (($previous['messageId'] ?? '') === $this->messageId
                && in_array($previous['status'] ?? '', ['done', 'stopped'], true))) {
            return;
        }

        if (($previous['messageId'] ?? $this->messageId) !== $this->messageId) {
            return;
        }

        if (($previous['attempted'] ?? '0') === '1') {
            $chatGenerationState->set($this->userId, $this->threadId, $this->messageId, 'error', true);
            $nonRetryableJobException = new \App\Services\Queue\NonRetryableJobException('Unsafe chat retry refused: agent may already have executed tools');
            try {
                $this->handleChatError($nonRetryableJobException);
            } finally {
                throw $nonRetryableJobException;
            }
        }

        $responseText = '';
        $semanticDocumentId = null;
        try {
            $brain = (string) $this->inMemorySession->get('brain_avatar');
            if (! $preStopped && ! $this->brainRegistry->has($brain)) {
                throw new \InvalidArgumentException('Assistant inconnu: ' . $brain);
            }
            $journal->begin(
                $this->messageId,
                $this->userId,
                $this->threadId,
                'web',
                $this->messageId,
                $this->submissionId,
                ['sessionId' => $this->sessionId],
                atomic: function (): void {
                    if ($this->settings->get('llm.semanticMemory.enabled', false) === true) {
                        $this->semanticMemoryRegistry?->captureTurn($this->userId, $this->messageId);
                    }
                }
            );
            if (! $preStopped) {
                $this->agent = $this->brainRegistry->get(
                    $this->inMemorySession->get('brain_avatar'),
                    $this->inMemorySession,
                    $this->threadId
                );
            }
            if (! $preStopped) {
                try {
                    $chatGenerationState->set($this->userId, $this->threadId, $this->messageId, 'running', false);
                    $this->publishStartMessages();
                    // Persist the fence BEFORE entering agent code, including middleware and tool execution.
                    $chatGenerationState->set($this->userId, $this->threadId, $this->messageId, 'running', true);
                } catch (\Throwable $projectionError) {
                    if (! ($this->stopToken?->isRequested() ?? false)) {
                        throw $projectionError;
                    }
                }
            }
            $responseText = $this->processChatStream($chatThreadLock);
            $chatThreadLock->assertHeld();
            $completed = $journal->complete(
                $this->messageId,
                $this->userId,
                $this->streamStopped ? 'stopped' : 'succeeded',
                function (Connection $_connection, string $status) use (&$semanticDocumentId): void {
                    if ($status === 'stopped') {
                        $history = new UserChatHistory(
                            $this->inMemorySession,
                            $this->connection->getNativeConnection(),
                            threadId: $this->threadId
                        );
                        $history->persistStoppedTurn($this->turnMessages, $this->messageId, $this->messageId);
                        return;
                    }
                    if ($this->settings->get('llm.semanticMemory.enabled', false) === true) {
                        $semanticDocumentId = $this->semanticMemoryRegistry?->recordSucceededTurn(
                            $this->userId,
                            $this->messageId,
                            $this->userClaireId(),
                            $this->messageId,
                            $this->userMessage,
                            $this->finalAssistantText,
                        );
                    }
                }
            );
            if (! in_array($completed['status'], ['succeeded', 'stopped'], true)) {
                throw new \RuntimeException('Chat turn was invalidated before completion');
            }
            $this->turnStatus = $completed['status'];
            if ($this->turnStatus === 'succeeded') {
                if ($semanticDocumentId !== null && $this->semanticQueue !== null) {
                    try {
                        $this->semanticQueue->dispatch(
                            \App\Job\SemanticMemory\IndexTurnJob::class,
                            ['userId' => $this->userId, 'documentId' => $semanticDocumentId],
                            (string) $this->settings->get('queue.defaultQueue'),
                        );
                    } catch (\Throwable $enqueueError) {
                        $this->logger->warning('Semantic indexing enqueue failed', ['error' => $enqueueError::class]);
                    }
                }
                try {
                    $this->manageSummary();
                } catch (\Throwable $throwable) {
                    $this->logger->error('Chat summary failed after successful generation', ['exception' => $throwable]);
                }
            } else {
                $this->autoAudioRequestId = null;
                $responseText = $this->streamedText;
            }

            $chatGenerationState->set(
                $this->userId,
                $this->threadId,
                $this->messageId,
                $this->turnStatus === 'stopped' ? 'stopped' : 'done',
                true
            );
        } catch (\Throwable $throwable) {
            // Resolve lost commit acknowledgements from SQL, never from Redis.
            $turn = $journal->get($this->messageId);
            if (in_array($turn['status'] ?? '', ['succeeded', 'stopped'], true)) {
                $this->turnStatus = $turn['status'];
                if ($this->turnStatus === 'stopped') {
                    $this->autoAudioRequestId = null;
                    $responseText = $this->streamedText;
                }
                try {
                    $chatGenerationState->set(
                        $this->userId,
                        $this->threadId,
                        $this->messageId,
                        $this->turnStatus === 'stopped' ? 'stopped' : 'done',
                        true
                    );
                } catch (\Throwable $projectionError) {
                    // Periodic recovery repairs the disposable projection.
                    $this->logger->warning('Chat success projection failed', ['exception' => $projectionError]);
                }
            } else {
                if ($turn === null) {
                    throw $throwable;
                }
                $turn = $journal->rollback($this->messageId, $this->userId);
                $this->turnStatus = $turn['status'];
                try {
                    $chatGenerationState->set($this->userId, $this->threadId, $this->messageId, 'error', true);
                    $this->handleChatError($throwable);
                } catch (\Throwable $reportError) {
                    $this->logger->error('Cannot report chat failure', ['exception' => $reportError]);
                }
                throw new \App\Services\Queue\NonRetryableJobException('Chat attempt failed after agent entry', 0, $throwable);
            }
        } finally {
            $this->agent = null;
            $chatThreadLock->release();
        }

        // Success is durable and the mutation lock is released before notifying clients.
        // A delivery failure must not turn a completed generation into a retryable one.
        if ($this->turnStatus === 'stopped') {
            try {
                if ($responseText !== '') {
                    $this->publishContent($responseText);
                }
                foreach ($this->turnMessages as $message) {
                    if ($message instanceof \NeuronAI\Chat\Messages\ToolResultMessage) {
                        foreach ($message->getToolCalls() as $call) {
                            $this->processToolChunk(new ToolResultChunk($call));
                        }
                    }
                }
                $this->publish('chat.assistant.stopped', [
                    'threadId' => $this->threadId, 'sessionId' => $this->sessionId, 'messageId' => $this->messageId,
                ]);
            } catch (\Throwable $projectionError) {
                // A stopped SQL turn is already acknowledged; reconnect/recovery restores the projection.
                $this->logger->warning('Cannot publish stopped generation', ['exception' => $projectionError]);
            }
            return;
        }
        $this->publishContent($responseText);
        $this->publishDoneMessages();
        try {
            $this->publishAudio($responseText);
        } catch (\Throwable $throwable) {
            $this->logger->error('Chat audio failed after successful generation', ['exception' => $throwable]);
        }
    }

    /**
     * Initializes the context using the given payload.
     *
     * @param array<string, mixed> $payload The input data containing the required fields
     *                                      such as 'message', 'threadId', 'sessionId',
     *                                      'session', 'brainAvatar', and optional 'attachments'.
     *                                      - 'message': string containing the user's message (required, non-empty).
     *                                      - 'threadId': string identifying the chat (required, non-empty).
     *                                      - 'sessionId': string identifying the session (required, non-empty).
     *                                      - 'session': an array or data structure used to initialize the session.
     *                                      - 'brainAvatar': a string used to fetch the appropriate agent.
     *                                      - 'attachments': an array containing 'uploadedFiles' and/or 'fileIds'.
     *
     * @throws \InvalidArgumentException If 'message', 'threadId', or 'sessionId' are missing or empty.
     */
    private function initContext(array $payload): void
    {
        $this->submissionId = isset($payload['submissionId']) ? (string) $payload['submissionId'] : null;
        $this->messageId = (string) ($payload['messageId'] ?? '');
        if ($this->messageId === '') {
            throw new \InvalidArgumentException('Stable message ID is required');
        }

        if (preg_match(UserChatHistory::MESSAGE_ID_PATTERN, $this->messageId) !== 1) {
            throw new \InvalidArgumentException('Invalid assistant message ID');
        }

        $this->userMessage = trim((string) ($payload['message'] ?? ''));
        if ($this->userMessage === '') {
            throw new \InvalidArgumentException('User message cannot be empty');
        }

        $this->threadId = (string) ($payload['threadId'] ?? '');
        if ($this->threadId === '') {
            throw new \InvalidArgumentException('Thread ID cannot be empty');
        }

        $this->sessionId = (string) ($payload['sessionId'] ?? '');
        if ($this->sessionId === '') {
            throw new \InvalidArgumentException('Session ID cannot be empty');
        }

        $this->inMemorySession = new InMemorySession($payload['session']);
        $this->userId = (string) $this->inMemorySession->get(Auth::USERID);
        $this->sessionId = ChatStreamSubscriber::scope($this->userId, $this->sessionId);

        if (is_array($payload['attachments'] ?? null)) {
            $this->attachments = array_merge($payload['attachments']['uploadedFiles'] ?? [], $payload['attachments']['fileIds'] ?? []);
        }
    }

    private function processChatStream(ChatThreadLock $lock): string
    {
        $userMessage = new UserMessage($this->userMessage);
        $userMessage->addMetadata('timestamp', new DateTimeImmutable()->format(DateTimeInterface::ATOM));
        $userMessage->addMetadata('claire_submission_id', $this->userClaireId());
        $this->addAttachments($userMessage);

        $lock->assertHeld();
        if ($this->stopToken?->isRequested()) {
            $this->turnMessages = [$userMessage];
            $this->streamStopped = true;
            return '';
        }
        if ($this->stopToken !== null) {
            $provider = $this->agent->getProvider();
            if (method_exists($provider, 'getHttpClient') && method_exists($provider, 'setHttpClient')) {
                $provider = clone $provider;
                $provider->setHttpClient($this->stopToken->httpClient($provider->getHttpClient()));
            }
            $this->agent->setAiProvider($provider);
            $stream = new GenerationExecution($this->stopToken)->stream(
                $this->agent,
                $userMessage,
                $lock->assertHeld(...),
            );
        } else {
            $stream = $this->agent->stream($userMessage);
        }

        // valid() starts the generator; next() can execute tools before yielding.
        $lock->assertHeld();
        foreach ($stream as $chunk) {
            $lock->assertHeld();
            $this->publishPlaceHolder();
            $this->processChunk($chunk);
            $this->nbPublishedChunks++;
            // Resuming a ToolCallChunk can execute its tool before the next yield.
            $lock->assertHeld();
        }

        $result = $stream->getReturn();
        if ($result instanceof \App\Services\GenerationExecutionResult) {
            $this->turnMessages = $result->messages;
            $this->streamStopped = $result->stopped;
            if ($result->stopped) {
                return $this->streamedText;
            }
            $state = $result->state;
        } else {
            $state = $result;
            $this->turnMessages = $state->getSteps();
        }
        if ($state === null) {
            throw new \UnexpectedValueException('Web generation completed without state');
        }
        if ($state->isInterrupted()) {
            throw new \UnexpectedValueException('Unexpected workflow suspension during Web generation');
        }
        $message = $state->getMessage();
        if ($message === null) {
            throw new \UnexpectedValueException('Web generation completed without an assistant message');
        }
        if ($message->getMetadata('stop_reason') === \NeuronAI\HttpClient\StoppableHttpClient::STOP_REASON) {
            // The technical migration must not commit a stopped provider response as success.
            throw new \UnexpectedValueException('Stopped Web stream requires durable stop finalization');
        }
        // Replace the final stream's text with its postprocessed message, keeping earlier narration.
        $this->streamedMessages[$message->getId()] = $message->getContent() ?? '';
        $this->finalAssistantText = trim(
            preg_replace('/@@GENERATED@@[^@]+@@/', '', $message->getContent() ?? '') ?? '',
        );
        $responseText = implode('', $this->streamedMessages);
        $chatHistory = $this->agent->getUserChatHistory();
        if (! $chatHistory instanceof UserChatHistory) {
            throw new \RuntimeException('Persistent chat history is required for Web messages');
        }

        $this->autoAudioRequestId = $this->inMemorySession->get(
            AudioServiceInterface::AUTO_GENERATE_SESSION_KEY,
            false,
        ) === true ? 'auto-' . $this->messageId : null;
        $chatHistory->identifyLastAssistantMessage($this->messageId, $this->autoAudioRequestId);

        return $responseText;
    }

    private function userClaireId(): string
    {
        return $this->submissionId ?? 'user-' . hash('sha256', $this->messageId);
    }

    private function publishAudio(string $responseText): void
    {
        if ($this->autoAudioRequestId !== null) {
            $this->chatAudioPublisher->publish(
                $this->sessionId,
                $this->threadId,
                $this->messageId,
                $responseText,
                $this->inMemorySession,
                $this->autoAudioRequestId,
            );
        }
    }

    private function processChunk(mixed $chunk): void
    {
        if ($chunk === null) {
            return;
        }

        if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
            $this->processToolChunk($chunk);
            return;
        }

        if ($chunk instanceof ReasoningChunk) {
            return;
        }

        if ($chunk instanceof TextChunk) {
            $this->processTextChunk($chunk);
            return;
        }

        $this->logger->debug('Chunk type not handled: ' . $chunk::class);
    }

    private function processToolChunk(ToolCallChunk|ToolResultChunk $chunk): void
    {
        $tool = $chunk->tool;
        $id = $tool->getCallId();
        $toolData = [
            'id' => $id,
            'name' => $tool->getName(),
            'inputs' => [],
            'running' => $chunk instanceof ToolCallChunk,
            'result' => $chunk instanceof ToolResultChunk ? $tool->getResult() : null,
        ];
        foreach ($tool->getInputs() as $name => $val) {
            $toolData['inputs'][] = [
                'name' => $name,
                'value' => $val,
            ];
        }

        $this->toolsCall[$id] = $toolData;

        $this->publish('chat.tool.update', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'toolsCall' => array_values($this->toolsCall),
        ]);
    }

    private function processTextChunk(ReasoningChunk|TextChunk $chunk): void
    {
        if ($chunk->content === '') {
            return;
        }

        $this->streamedText .= $chunk->content;
        $this->streamedMessages[$chunk->messageId] = ($this->streamedMessages[$chunk->messageId] ?? '') . $chunk->content;

        $content = $this->chatDataRenderer->content($this->streamedText, $this->userId, true);

        $this->publish('chat.assistant.update', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            ...$content,
        ]);
    }

    private function publishStartMessages(): void
    {
        $this->publish('chat.assistant.start', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
        ]);
    }

    private function publishDoneMessages(): void
    {
        $this->publish('chat.assistant.done', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'audioRequestId' => $this->autoAudioRequestId,
        ]);
    }

    private function publishPlaceHolder(): void
    {
        if ($this->nbPublishedChunks % 10 !== 0) {
            return;
        }

        $placeholder = $this->chatDataRenderer->message([
            'id' => $this->messageId,
            'message' => '',
            'time' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'sent' => false,
        ], $this->userId);
        $this->publish('chat.assistant.placeholder', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'entry' => $placeholder,
        ]);
    }

    private function publishContent(string $content): void
    {
        $data = $this->chatDataRenderer->content($content, $this->userId);

        $this->publish('chat.assistant.update', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            ...$data,
        ]);
    }

    private function handleChatError(\Throwable $throwable): void
    {
        $this->logger->error('Web chat job failed', [
            'exception' => $throwable,
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
        ]);
        $this->publish('chat.error', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'message' => 'Désolé, une erreur est survenue lors du traitement de votre message.',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $event, array $payload): void
    {
        $this->chatStreamPublisher->publish($this->sessionId, $event, $payload + [
            'submissionId' => $this->submissionId,
            'turnStatus' => $this->turnStatus,
            'rollbackConfirmed' => $this->turnStatus === 'rolled_back',
        ]);
    }

    private function addAttachments(UserMessage $userMessage): void
    {
        if (! is_array($this->attachments)) {
            return;
        }

        foreach ($this->attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $content = (string) ($attachment['content'] ?? '');
            if ($content === '') {
                continue;
            }

            $userMessage->addContent(new \NeuronAI\Chat\Messages\ContentBlocks\FileContent(
                $content,
                \NeuronAI\Chat\Enums\SourceType::BASE64,
                (string) ($attachment['mimeType'] ?? 'application/octet-stream'),
                (string) ($attachment['filename'] ?? 'file'),
            ));
        }
    }

    private function manageSummary(): void
    {
        $summary = new Summary($this->connection, $this->settings, $this->inMemorySession, $this->threadId);
        $chatHistory = $summary->getUserChatHistory();

        if (! ($chatHistory instanceof \App\Brain\ChatHistory\UserChatHistory)) {
            $this->logger->error('Summary not available for non-user chat history');
            return;
        }

        $messages = $chatHistory->getDisplayMessages();
        $longTermMemory = new \App\Brain\LongTermMemory(
            connection: $this->connection,
            session: $this->inMemorySession,
            maxCharacters: $this->settings->get('llm.longTermMemory.maxCharacters'),
            updateEveryUserMessages: $this->settings->get(
                'llm.longTermMemory.updateEveryUserMessages'
            ),
        );
        $userMessageCount = count(array_filter(
            $messages,
            static fn (\NeuronAI\Chat\Messages\Message $message): bool => $message instanceof UserMessage
        ));
        $shouldEvolveLongTermMemory = $longTermMemory->shouldEvolve($userMessageCount);

        if (! $shouldEvolveLongTermMemory
            && $messages !== [] && count($messages) >= $this->settings->get('llm.summary.minMessages')
            && count($messages) <= $this->settings->get('llm.summary.maxMessages')
            && $chatHistory->getSummary() !== null && $chatHistory->getSummary() !== '') {
            return;
        }

        $summary->generateAndPersist($shouldEvolveLongTermMemory);
    }
}
