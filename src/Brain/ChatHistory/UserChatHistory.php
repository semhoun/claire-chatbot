<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;

/**
 * Based on NeuronAI\Chat\History\SQLChatHistory.
 */
class UserChatHistory
{
    public const string TABLE = 'chat_history';

    public const string LLM_MESSAGES_COLUMN = 'messages';

    public const string DISPLAY_MESSAGES_COLUMN = 'display_messages';

    public const string DISPLAY_MESSAGES_COUNT_COLUMN = 'display_messages_count';

    public const string CHAT_WEB = 'web';

    public const string CHAT_TELEGRAM = 'telegram';

    public const string MESSAGE_ID_METADATA = 'claire_message_id';

    public const string MESSAGE_ID_PATTERN = '/\A[A-Za-z][A-Za-z0-9_.:-]{0,127}\z/';

    public const string AUDIO_REQUEST_ID_METADATA = 'claire_audio_request_id';

    public const string AUDIO_REQUEST_ID_PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';
    /** @var list<Message> */
    protected array $history = [];

    protected ?string $title = null;

    protected ?string $summary = null;

    /**
     * @var array<Message>
     */
    protected array $displayHistory = [];

    /** @var array<string, array{message: Message, archived: bool}> */
    private array $stored = [];

    private ?string $loadedStoredMessages = null;

    private readonly mixed $ownerId;

    private bool $loaded = false;

    private ?UserMessageStore $store = null;

    private string $loadedMessages = '[]';

    private string $loadedDisplayMessages = '[]';

    private ?string $loadedVersion = null;

    private ?int $loadedRevision = null;

    private ?string $loadedTurnId = null;

    public function __construct(
        protected SessionInterface $session,
        protected PDO $pdo,
        protected int $contextWindow = 50000,
        protected ?string $threadId = null,
        private readonly bool $createIfMissing = true,
    ) {
        $this->ownerId = $session->get(Auth::USERID);
        if ($this->threadId !== null) {
            $this->load();
        }
    }

    public function messageStore(): UserMessageStore
    {
        return $this->store ??= new UserMessageStore($this);
    }

    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    public function assertOwner(): void
    {
        if ($this->ownerId === null || $this->ownerId === ''
            || $this->session->get(Auth::USERID) !== $this->ownerId) {
            throw new \RuntimeException('Chat history owner mismatch');
        }
    }

    /** @return list<Message> */
    public function getMessages(): array
    {
        $this->assertOwner();
        return $this->history;
    }

    public function getLastMessage(): Message|false
    {
        $this->assertOwner();
        return end($this->history);
    }

    public function addMessage(Message $message): self
    {
        $this->assertOwner();
        if (isset($this->stored[$message->getId()])) {
            return $this;
        }
        $this->history[] = clone $message;
        $this->onNewMessage(clone $message);
        $this->persistHistories();
        return $this;
    }

    /** @return list<Message> */
    public function getStoredMessages(?int $limit = null, ?string $before = null): array
    {
        $this->assertOwner();
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('Invalid history page limit');
        }
        $messages = array_column(array_values($this->stored), 'message');
        if ($before !== null) {
            $index = array_search($before, array_keys($this->stored), true);
            $messages = $index === false ? [] : array_slice($messages, 0, $index);
        }
        return $limit === null ? $messages : array_slice($messages, -$limit);
    }

    public function archiveMessages(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Invalid archive count');
        }
        if ($count > 0) {
            $this->history = array_slice($this->history, $count);
            $this->persistHistories();
        }
    }

    /** Explicit post-processing, never an append with an existing ID. */
    public function updateMessage(Message $message): void
    {
        $this->assertOwner();
        if (! isset($this->stored[$message->getId()])) {
            throw new \RuntimeException('Cannot update an unknown history message');
        }
        foreach ($this->history as &$active) {
            if ($active->getId() === $message->getId()) {
                $active = clone $message;
            }
        }
        unset($active);
        foreach ($this->displayHistory as &$visible) {
            if ($visible->getId() === $message->getId()) {
                $metadata = $visible->jsonSerialize()['__meta'];
                $visible = clone $message;
                foreach ([self::MESSAGE_ID_METADATA, self::AUDIO_REQUEST_ID_METADATA] as $key) {
                    if (array_key_exists($key, $metadata)) {
                        $visible->addMetadata($key, $metadata[$key]);
                    }
                }
            }
        }
        unset($visible);
        $this->stored[$message->getId()]['message'] = clone $message;
        $this->persistHistories();
    }

    /**
     * Call on a fresh facade inside complete()'s atomic callback, after the stopped CAS.
     * The helper's entire current turn replaces its possibly already-written prefix by ID.
     *
     * @param list<Message> $messages
     */
    public function persistStoppedTurn(array $messages, string $assistantClaireId, string $turnId): void
    {
        $this->assertOwner();
        if (! $this->pdo->inTransaction() || $this->threadId === null) {
            throw new \RuntimeException('Stopped history requires the terminal transaction');
        }
        if (preg_match(self::MESSAGE_ID_PATTERN, $assistantClaireId) !== 1) {
            throw new \InvalidArgumentException('Invalid stopped assistant identity');
        }
        $statement = $this->pdo->prepare('SELECT id, user_id, thread_id, status FROM chat_turn'
            . ' WHERE user_id = ? AND thread_id = ? ORDER BY history_revision DESC LIMIT 1');
        $statement->execute([$this->ownerId, $this->threadId]);
        $turn = $statement->fetch(PDO::FETCH_ASSOC);
        if ($turn === false || $turn['id'] !== $turnId || $turn['user_id'] !== (string) $this->ownerId
            || $turn['thread_id'] !== $this->threadId || $turn['status'] !== 'stopped') {
            throw new \RuntimeException('Stopped history terminal identity mismatch');
        }
        $this->refresh(); // Never bypass a stale facade's revision/snapshot/xmin guard.
        if ($this->loadedTurnId !== null || $messages === []
            || ! $messages[0] instanceof UserMessage || $messages[0] instanceof ToolResultMessage) {
            throw new \RuntimeException('Stopped history requires accepted user input');
        }
        $messages = array_map(static fn (Message $message): Message => clone $message, $messages);
        $ids = array_map(static fn (Message $message): string => $message->getId(), $messages);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Duplicate stopped message identity');
        }
        $last = $messages[array_key_last($messages)];
        if ($last instanceof ToolCallMessage) {
            throw new \InvalidArgumentException('Stopped history contains unanswered tool calls');
        }
        new \App\Services\GenerationStopHistoryTrimmer()->trim($messages, PHP_INT_MAX);
        foreach ($messages as $index => $message) {
            if ($message instanceof ToolResultMessage) {
                $calls = $messages[$index - 1]->getToolCalls();
                $results = $message->getToolCalls();
                if (array_map(static fn ($call) => $call->getCallId(), $calls)
                    !== array_map(static fn ($call) => $call->getCallId(), $results)
                    || array_any($results, static fn ($call): bool => ! $call->hasResult())) {
                    throw new \InvalidArgumentException('Stopped history contains incomplete tool results');
                }
            }
            $meta = $message->jsonSerialize()['__meta'];
            if (! array_key_exists('timestamp', $meta)) {
                $meta['timestamp'] = ($this->stored[$message->getId()]['message'] ?? null)
                    ?->getMetadata('timestamp') ?? gmdate(DATE_ATOM);
            }
            unset($meta[self::AUDIO_REQUEST_ID_METADATA], $meta[self::MESSAGE_ID_METADATA]);
            $message->setMetadata($meta);
        }
        $last->addMetadata('generation_stopped', true);
        $firstId = $ids[0];
        $cutoff = array_search($firstId, array_keys($this->stored), true);
        $prefix = $cutoff === false ? $this->stored : array_slice($this->stored, 0, $cutoff, true);
        foreach ($ids as $id) {
            if (isset($prefix[$id])) {
                throw new \RuntimeException('Stopped message belongs to an earlier turn');
            }
        }
        $keep = static fn (Message $message): bool => isset($prefix[$message->getId()]);
        $this->history = [...array_filter($this->history, $keep), ...$messages];
        $this->displayHistory = array_values(array_filter($this->displayHistory, $keep));
        $this->stored = $prefix;
        foreach ($messages as $message) {
            $visible = clone $message;
            if ($message === $last && ($message instanceof AssistantMessage || $message instanceof ToolResultMessage)) {
                $visible->addMetadata(self::MESSAGE_ID_METADATA, $assistantClaireId);
            }
            $this->onNewMessage($visible);
        }
        $this->persistHistories();
    }

    public function flushAll(): self
    {
        $this->clear();
        return $this;
    }

    public function setThreadId(string $threadId): void
    {
        $this->assertOwner();
        if ($this->threadId === $threadId) {
            return;
        }

        $this->threadId = $threadId;
        $this->store = null;
        $this->loadedRevision = null;
        $this->loadedTurnId = null;
        $this->loaded = false;
        $this->load();
    }

    /** @param array<Message> $messages */
    public function replaceMessages(array $messages): void
    {
        $this->setMessages($messages);
    }

    /**
     * @param array<Message> $messages
     */
    public function replaceDisplayMessages(array $messages): void
    {
        $this->setDisplayMessages($messages);
    }

    public function initializeWithOpeningMessage(AssistantMessage $assistantMessage, bool $display = true): void
    {
        $this->history = [$this->openingContextMessage(), $assistantMessage];
        $this->displayHistory = $display ? [$assistantMessage] : [];
        $this->persistHistories();
    }

    /** @return array<Message> */
    public function getDisplayMessages(): array
    {
        $this->assertOwner();
        return $this->displayHistory;
    }

    /** Persist the Web/audio identity on the final display message, without changing LLM context. */
    public function identifyLastAssistantMessage(string $messageId, ?string $audioRequestId = null): void
    {
        if (preg_match(self::MESSAGE_ID_PATTERN, $messageId) !== 1) {
            throw new \InvalidArgumentException('Invalid assistant message ID');
        }

        if ($audioRequestId !== null && preg_match(self::AUDIO_REQUEST_ID_PATTERN, $audioRequestId) !== 1) {
            throw new \InvalidArgumentException('Invalid audio request ID');
        }

        $index = array_key_last($this->displayHistory);
        $message = $index === null ? null : $this->displayHistory[$index];
        if (! $message instanceof AssistantMessage || $message instanceof ToolCallMessage) {
            throw new \RuntimeException('Final assistant message is missing from display history');
        }

        $this->displayHistory[$index] = (clone $message)
            ->addMetadata(self::MESSAGE_ID_METADATA, $messageId)
            ->addMetadata(self::AUDIO_REQUEST_ID_METADATA, $audioRequestId);
        $this->persistHistories();
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function removeLastExchange(): ?string
    {
        $this->assertOwner();
        $before = [...$this->history, ...$this->displayHistory];
        $lastUserMessage = $this->removeLastExchangeFromMessages($this->history);
        $lastVisibleUser = $this->removeLastExchangeFromMessages($this->displayHistory);
        $lastUserMessage = $lastVisibleUser ?? $lastUserMessage;
        if (! $lastUserMessage instanceof UserMessage) {
            return null;
        }

        $cutoff = array_search($lastUserMessage->getId(), array_keys($this->stored), true);
        if ($cutoff !== false) {
            $this->stored = array_slice($this->stored, 0, $cutoff, true);
        }

        $remaining = array_flip(array_map(
            static fn (Message $message): string => $message->getId(),
            [...$this->history, ...$this->displayHistory]
        ));
        foreach ($before as $message) {
            if (! isset($remaining[$message->getId()])) {
                unset($this->stored[$message->getId()]);
            }
        }

        $this->persistHistories();

        return $lastUserMessage->getContent();
    }

    /** @return array<int, array<string, mixed>> */
    public function getFormattedMessages(): array
    {
        $this->assertOwner();
        if ($this->displayHistory === []) {
            return [];
        }

        return new MessageFormatter($this->displayHistory)->format();
    }

    public function refresh(): void
    {
        $this->load();
    }

    public function validateMessageSequences(): void
    {
        $llmMessages = $this->fixMessageSequence($this->history);

        if (count($llmMessages) === count($this->history)) {
            return;
        }

        $this->history = $llmMessages;
        $this->persistHistories();
    }

    protected function load(): void
    {
        $this->assertOwner();
        $versionColumn = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? ', xmin::text AS version' : '';
        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT %s, %s, stored_messages, user_id, thread_id, title, summary, revision, current_turn_id' . $versionColumn
                    . ' FROM %s WHERE user_id = :user_id AND thread_id = :thread_id',
                self::LLM_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COLUMN,
                self::TABLE
            )
        );
        $stmt->execute([
            'user_id' => $this->session->get(Auth::USERID),
            'thread_id' => $this->threadId,
        ]);

        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($history === []) {
            if ($this->loadedRevision !== null) {
                throw new \RuntimeException('Chat history changed or was deleted; refusing stale snapshot');
            }
            $this->history = [];
            $this->displayHistory = [];
            $this->title = null;
            $this->summary = null;
            $this->stored = [];
            $this->loadedMessages = $this->loadedDisplayMessages = '[]';
            $this->loadedStoredMessages = $this->loadedVersion = null;
            $this->loaded = true;
            return;
        }

        $history = $history[0];
        if ($this->loaded && $this->loadedRevision === null) {
            throw new \RuntimeException('Chat history changed or was deleted; refusing stale snapshot');
        }
        $this->loaded = true;
        if ((string) $history['user_id'] !== (string) $this->ownerId || $history['thread_id'] !== $this->threadId) {
            throw new \RuntimeException('Chat history owner or thread mismatch');
        }
        if ($this->loadedRevision !== null
            && ($this->loadedRevision !== (int) $history['revision']
                || $this->loadedTurnId !== $history['current_turn_id']
                || $this->loadedMessages !== $history[self::LLM_MESSAGES_COLUMN]
                || $this->loadedDisplayMessages !== $history[self::DISPLAY_MESSAGES_COLUMN]
                || $this->loadedStoredMessages !== $history['stored_messages']
                || $this->loadedVersion !== ($history['version'] ?? null))) {
            throw new \RuntimeException('Chat history changed or was deleted; refusing stale snapshot');
        }
        $this->loadedRevision = (int) $history['revision'];
        $this->loadedTurnId = $history['current_turn_id'];
        $this->loadedMessages = (string) $history[self::LLM_MESSAGES_COLUMN];
        $this->loadedDisplayMessages = (string) $history[self::DISPLAY_MESSAGES_COLUMN];
        $this->loadedVersion = $history['version'] ?? null;
        $this->loadedStoredMessages = $history['stored_messages'];

        $this->title = isset($history['title']) ? (string) $history['title'] : null;
        $this->summary = isset($history['summary']) ? (string) $history['summary'] : null;

        $llmPayload = json_decode(
            (string) $history[self::LLM_MESSAGES_COLUMN],
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $displayPayload = json_decode(
            (string) $history[self::DISPLAY_MESSAGES_COLUMN],
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->displayHistory = $this->deserializeMessages($displayPayload, 'display');
        $this->history = $this->deserializeMessages($llmPayload, 'active');
        // Match legacy projections by occurrence, not by content alone: identical turns remain distinct.
        $cursor = count($this->displayHistory) - 1;
        foreach (array_reverse($this->history, true) as $index => $message) {
            if (isset($llmPayload[$index]['__id'])) {
                foreach ($this->displayHistory as $visibleIndex => $visible) {
                    if ($visible->getId() === $message->getId()) {
                        $cursor = $visibleIndex - 1;
                        break;
                    }
                }
                continue;
            }
            for ($visibleIndex = $cursor; $visibleIndex >= 0; --$visibleIndex) {
                $visible = $this->displayHistory[$visibleIndex];
                if ($this->fingerprint($message) === $this->fingerprint($visible)) {
                    $message->setId($visible->getId());
                    $cursor = $visibleIndex - 1;
                    break;
                }
            }
        }
        $this->stored = [];
        if ($this->loadedStoredMessages !== null) {
            foreach (json_decode($this->loadedStoredMessages, true, flags: JSON_THROW_ON_ERROR) as $record) {
                $message = new MessageDeserializer()->deserialize($record['message']);
                $this->stored[$message->getId()] = ['message' => $message, 'archived' => $record['archived']];
            }
        }

        if (($this->history[0] ?? null) instanceof AssistantMessage) {
            array_unshift($this->history, $this->openingContextMessage());
        }
        $this->synchronizeStored();
    }

    protected function onNewMessage(Message $message): void
    {
        if ($message->getMetadata('message_type') === 'out_of_context') {
            return;
        }

        $this->displayHistory[] = $message;
    }

    /** @param array<Message> $messages */
    protected function setMessages(array $messages): void
    {
        $this->history = $messages;

        if ($this->displayHistory === []) {
            $this->displayHistory = [];
            foreach ($messages as $message) {
                if ($message->getMetadata('message_type') === 'out_of_context') {
                    continue;
                }

                $this->displayHistory[] = $message;
            }
        }

        $this->persistHistories();
    }

    /**
     * @param array<Message> $messages
     */
    protected function setDisplayMessages(array $messages): void
    {
        $this->displayHistory = $messages;
        $this->persistHistories();
    }

    protected function clear(): void
    {
        $this->stored = [];
        $this->history = [];
        $this->displayHistory = [];
        $this->persistHistories(clearMetadata: true);
        $this->title = null;
        $this->summary = null;
    }

    /**
     * Fix message sequence by removing invalid messages.
     *
     * Ensures proper user/assistant alternation and tool call/tool result pairing.
     * Last message must be an assistant message.
     *
     * @param array<Message> $messages
     *
     * @return array<Message>
     */
    protected function fixMessageSequence(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        return new MessageSequenceFixer()->fix($messages);
    }

    /**
     * @param array<Message> $messages
     */
    private function removeLastExchangeFromMessages(array &$messages): ?UserMessage
    {
        while ($messages !== []) {
            $message = array_pop($messages);

            if ($message instanceof UserMessage && ! $message instanceof ToolResultMessage) {
                return $message;
            }
        }

        return null;
    }

    private function persistHistories(bool $clearMetadata = false): void
    {
        $this->assertOwner();
        $this->synchronizeStored();
        if ($this->threadId === null) {
            return;
        }

        $messages = json_encode($this->serializeMessages($this->history), JSON_THROW_ON_ERROR);
        $display = json_encode($this->serializeMessages($this->displayHistory), JSON_THROW_ON_ERROR);
        $stored = json_encode(array_values(array_map(
            static fn (array $record): array => ['message' => $record['message']->jsonSerialize(),
                'archived' => $record['archived'],
            ],
            $this->stored
        )), JSON_THROW_ON_ERROR);

        if ($this->loadedRevision === null) {
            if (! $this->createIfMissing) {
                throw new \RuntimeException('Chat history missing; refusing stale snapshot');
            }
            $postgres = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
            $insert = $this->pdo->prepare('INSERT INTO chat_history'
                . ' (user_id, thread_id, messages, display_messages, display_messages_count, stored_messages, revision)'
                . ' VALUES (?, ?, ?, ?, ?, ?, 1)' . ($postgres ? ' RETURNING xmin::text' : ''));
            try {
                $insert->execute([$this->ownerId, $this->threadId, $messages, $display,
                    count($this->displayHistory), $stored,
                ]);
            } catch (\PDOException $exception) {
                throw new \RuntimeException('Chat history changed or was deleted; refusing stale snapshot', 0, $exception);
            }
            $this->loadedRevision = 1;
            $this->loadedMessages = $messages;
            $this->loadedDisplayMessages = $display;
            $this->loadedStoredMessages = $stored;
            $this->loadedVersion = $postgres ? (string) $insert->fetchColumn() : null;
            return;
        }

        $versionGuard = $this->loadedVersion !== null ? ' AND xmin::text = :version' : '';
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $snapshotGuard = $mysql
            ? ' AND CAST(messages AS BINARY) = CAST(:loaded_messages AS BINARY)'
                . ' AND CAST(display_messages AS BINARY) = CAST(:loaded_display_messages AS BINARY)'
            : ' AND messages = :loaded_messages AND display_messages = :loaded_display_messages';
        $snapshotGuard .= $this->loadedStoredMessages === null ? ' AND stored_messages IS NULL'
            : ($mysql ? ' AND CAST(stored_messages AS BINARY) = CAST(:loaded_stored_messages AS BINARY)'
                : ' AND stored_messages = :loaded_stored_messages');
        $turnGuard = $this->loadedTurnId === null
            ? ' AND current_turn_id IS NULL' : ' AND current_turn_id = :loaded_turn_id';
        $stmt = $this->pdo->prepare(
            sprintf(
                'UPDATE %s SET %s = :llm_messages, %s = :display_messages, %s = :display_messages_count'
                    . ', stored_messages = :stored_messages, revision = revision + 1'
                    . ($clearMetadata ? ', title = NULL, summary = NULL' : '')
                    . ' WHERE thread_id = :thread_id AND user_id = :user_id'
                    . $snapshotGuard
                    . ' AND revision = :loaded_revision' . $turnGuard
                    . $versionGuard . ($this->loadedVersion !== null ? ' RETURNING xmin::text' : ''),
                self::TABLE,
                self::LLM_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COUNT_COLUMN
            )
        );
        $parameters = [
            'thread_id' => $this->threadId,
            'user_id' => $this->session->get(Auth::USERID),
            'llm_messages' => $messages,
            'display_messages' => $display,
            'display_messages_count' => count($this->displayHistory),
            'loaded_messages' => $this->loadedMessages,
            'loaded_display_messages' => $this->loadedDisplayMessages,
            'loaded_revision' => $this->loadedRevision,
            'stored_messages' => $stored,
        ];
        if ($this->loadedStoredMessages !== null) {
            $parameters['loaded_stored_messages'] = $this->loadedStoredMessages;
        }
        if ($this->loadedTurnId !== null) {
            $parameters['loaded_turn_id'] = $this->loadedTurnId;
        }
        if ($this->loadedVersion !== null) {
            $parameters['version'] = $this->loadedVersion;
        }

        $stmt->execute($parameters);
        $matched = $stmt->rowCount() === 1;

        if (! $matched) {
            throw new \RuntimeException('Chat history changed or was deleted; refusing stale snapshot');
        }

        if ($this->loadedVersion !== null) {
            $this->loadedVersion = (string) $stmt->fetchColumn();
        }

        $this->loadedMessages = $parameters['llm_messages'];
        $this->loadedDisplayMessages = $parameters['display_messages'];
        $this->loadedStoredMessages = $parameters['stored_messages'];
        ++$this->loadedRevision;
    }

    private function openingContextMessage(): UserMessage
    {
        $userMessage = new UserMessage(
            <<<'CONTEXT'
[OC]
Le message assistant suivant est le message d’ouverture déjà envoyé à l’utilisateur.
Prends-le en compte dans la suite de la conversation.
[/OC]
CONTEXT
        );
        $userMessage->addMetadata('message_type', 'out_of_context');
        $userMessage->setId('legacy-opening-' . hash('sha256', (string) $this->ownerId . ':' . $this->threadId));

        return $userMessage;
    }

    /**
     * @param array<Message> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function serializeMessages(array $messages): array
    {
        return array_map(
            static fn (Message $message): array => $message->jsonSerialize(),
            $messages,
        );
    }

    /** @param list<array<string, mixed>> $payload @return list<Message> */
    private function deserializeMessages(array $payload, string $projection): array
    {
        $messages = [];
        foreach ($payload as $index => $data) {
            $data['__id'] ??= 'legacy-' . hash('sha256', json_encode(
                [$this->ownerId, $this->threadId, $projection, $index, $data],
                JSON_THROW_ON_ERROR
            ));
            $messages[] = new MessageDeserializer()->deserialize($data);
        }
        return $messages;
    }

    private function fingerprint(Message $message): string
    {
        $data = $message->jsonSerialize();
        unset($data['__id'], $data['__meta'][self::MESSAGE_ID_METADATA],
            $data['__meta'][self::AUDIO_REQUEST_ID_METADATA]);
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function synchronizeStored(): void
    {
        // Insert newly recovered internal messages immediately before their next known active neighbour.
        if ($this->stored === []) {
            foreach ($this->displayHistory as $message) {
                $this->stored[$message->getId()] = ['message' => clone $message, 'archived' => true];
            }
            foreach ($this->history as $index => $message) {
                if (isset($this->stored[$message->getId()])) {
                    continue;
                }
                $position = count($this->stored);
                foreach (array_slice($this->history, $index + 1) as $next) {
                    $found = array_search($next->getId(), array_keys($this->stored), true);
                    if ($found !== false) {
                        $position = $found;
                        break;
                    }
                }
                $this->stored = array_slice($this->stored, 0, $position, true)
                    + [$message->getId() => ['message' => clone $message, 'archived' => false]]
                    + array_slice($this->stored, $position, null, true);
            }
        }
        foreach ($this->stored as &$record) {
            $record['archived'] = true;
        }
        unset($record);
        foreach ($this->displayHistory as $message) {
            $this->stored[$message->getId()] = ['message' => clone $message, 'archived' => true];
        }
        foreach ($this->history as $message) {
            $id = $message->getId();
            $this->stored[$id] ??= ['message' => clone $message, 'archived' => false];
            $this->stored[$id]['archived'] = false;
        }
    }
}
