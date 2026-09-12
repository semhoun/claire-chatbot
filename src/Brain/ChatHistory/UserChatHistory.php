<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;

/**
 * Based on NeuronAI\Chat\History\SQLChatHistory.
 */
class UserChatHistory extends AbstractChatHistory
{
    public const string TABLE = 'chat_history';

    public const string LLM_MESSAGES_COLUMN = 'messages';

    public const string DISPLAY_MESSAGES_COLUMN = 'display_messages';

    public const string DISPLAY_MESSAGES_COUNT_COLUMN = 'display_messages_count';

    public const string CHAT_WEB = 'web';

    public const string CHAT_TELEGRAM = 'telegram';

    public const string MESSAGE_ID_METADATA = 'claire_message_id';

    public const string MESSAGE_ID_PATTERN = '/\A[A-Za-z][A-Za-z0-9_.:-]{0,127}\z/';

    protected ?string $title = null;

    protected ?string $summary = null;

    /**
     * @var array<Message>
     */
    protected array $displayHistory = [];

    private string $loadedMessages = '[]';

    private string $loadedDisplayMessages = '[]';

    private ?string $loadedVersion = null;

    public function __construct(
        protected SessionInterface $session,
        protected PDO $pdo,
        protected int $contextWindow = 50000,
        protected ?string $threadId = null,
        private readonly bool $createIfMissing = true,
    ) {
        if ($this->threadId !== null) {
            $this->load();
        }

        parent::__construct($contextWindow);
    }

    public function setThreadId(string $threadId): void
    {
        if ($this->threadId === $threadId) {
            return;
        }

        $this->threadId = $threadId;
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
        return $this->displayHistory;
    }

    /** Persist the Web/audio identity on the final display message, without changing LLM context. */
    public function identifyLastAssistantMessage(string $messageId): void
    {
        if (preg_match(self::MESSAGE_ID_PATTERN, $messageId) !== 1) {
            throw new \InvalidArgumentException('Invalid assistant message ID');
        }

        $index = array_key_last($this->displayHistory);
        $message = $index === null ? null : $this->displayHistory[$index];
        if (! $message instanceof AssistantMessage || $message instanceof ToolCallMessage) {
            throw new \RuntimeException('Final assistant message is missing from display history');
        }

        $this->displayHistory[$index] = (clone $message)->addMetadata(self::MESSAGE_ID_METADATA, $messageId);
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
        $lastUserMessage = $this->removeLastExchangeFromMessages($this->history);
        if (! $lastUserMessage instanceof \NeuronAI\Chat\Messages\UserMessage) {
            return null;
        }

        $this->removeLastExchangeFromMessages($this->displayHistory);

        $this->persistHistories();

        return $lastUserMessage->getContent();
    }

    /** @return array<int, array<string, mixed>> */
    public function getFormattedMessages(): array
    {
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
        $versionColumn = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? ', xmin::text AS version' : '';
        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT %s, %s, title, summary' . $versionColumn
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
            $this->history = [];
            $this->displayHistory = [];
            $this->title = null;
            $this->summary = null;
            if (! $this->createIfMissing) {
                return;
            }

            $stmt = $this->pdo->prepare(
                sprintf(
                    'INSERT INTO %s (user_id, thread_id, %s, %s, %s) VALUES (:user_id, :thread_id, :messages, :display_messages, :display_messages_count)',
                    self::TABLE,
                    self::LLM_MESSAGES_COLUMN,
                    self::DISPLAY_MESSAGES_COLUMN,
                    self::DISPLAY_MESSAGES_COUNT_COLUMN
                )
            );
            $stmt->execute([
                'user_id' => $this->session->get(Auth::USERID),
                'thread_id' => $this->threadId,
                self::LLM_MESSAGES_COLUMN => '[]',
                self::DISPLAY_MESSAGES_COLUMN => '[]',
                self::DISPLAY_MESSAGES_COUNT_COLUMN => 0,

            ]);
            $this->history = [];
            $this->displayHistory = [];
            $this->title = null;
            $this->summary = null;

            $this->load();
            return;
        }

        $history = $history[0];
        $this->loadedMessages = (string) $history[self::LLM_MESSAGES_COLUMN];
        $this->loadedDisplayMessages = (string) $history[self::DISPLAY_MESSAGES_COLUMN];
        $this->loadedVersion = $history['version'] ?? null;

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

        $this->history = $this->deserializeMessages($llmPayload);
        $this->displayHistory = $this->deserializeMessages($displayPayload);

        if (($this->history[0] ?? null) instanceof AssistantMessage) {
            array_unshift($this->history, $this->openingContextMessage());
            if ($this->createIfMissing) {
                $this->persistHistories();
            }
        }
    }

    #[\Override]
    protected function onNewMessage(Message $message): void
    {
        if ($message->getMetadata('message_type') === 'out_of_context') {
            return;
        }

        $this->displayHistory[] = $message;
    }

    #[\Override]
    /** @param array<Message> $messages */
    protected function setMessages(array $messages): void
    {
        $this->history = $messages;

        if ($this->displayHistory === []) {
            $this->displayHistory = [];
            foreach ($messages as $message) {
                if ($message->getMetadata('message_type') === 'out_of_context') {
                    return;
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

    #[\Override]
    protected function clear(): void
    {
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
        if ($this->threadId === null) {
            return;
        }

        $versionGuard = $this->loadedVersion !== null ? ' AND xmin::text = :version' : '';
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $snapshotGuard = $mysql
            ? ' AND CAST(messages AS BINARY) = CAST(:loaded_messages AS BINARY)'
                . ' AND CAST(display_messages AS BINARY) = CAST(:loaded_display_messages AS BINARY)'
            : ' AND messages = :loaded_messages AND display_messages = :loaded_display_messages';
        $stmt = $this->pdo->prepare(
            sprintf(
                'UPDATE %s SET %s = :llm_messages, %s = :display_messages, %s = :display_messages_count'
                    . ($clearMetadata ? ', title = NULL, summary = NULL' : '')
                    . ' WHERE thread_id = :thread_id AND user_id = :user_id'
                    . $snapshotGuard
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
            'llm_messages' => json_encode(
                $this->serializeMessages($this->history),
                JSON_THROW_ON_ERROR
            ),
            'display_messages' => json_encode(
                $this->serializeMessages($this->displayHistory),
                JSON_THROW_ON_ERROR
            ),
            'display_messages_count' => count($this->displayHistory),
            'loaded_messages' => $this->loadedMessages,
            'loaded_display_messages' => $this->loadedDisplayMessages,
        ];
        if ($this->loadedVersion !== null) {
            $parameters['version'] = $this->loadedVersion;
        }

        $stmt->execute($parameters);
        $matched = $stmt->rowCount() === 1;
        if (! $matched && $mysql
            && $parameters['llm_messages'] === $this->loadedMessages
            && $parameters['display_messages'] === $this->loadedDisplayMessages) {
            // MySQL counts changed rows by default. Verify a no-op using a current,
            // locking read, not an older REPEATABLE READ transaction snapshot.
            $check = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE thread_id = :thread_id AND user_id = :user_id'
                    . $snapshotGuard . ' AND display_messages_count = :display_messages_count'
                    . ($clearMetadata ? ' AND title IS NULL AND summary IS NULL' : '') . ' FOR UPDATE'
            );
            $check->execute([
                'thread_id' => $this->threadId,
                'user_id' => $parameters['user_id'],
                'loaded_messages' => $this->loadedMessages,
                'loaded_display_messages' => $this->loadedDisplayMessages,
                'display_messages_count' => $parameters['display_messages_count'],
            ]);
            $matched = $check->fetchColumn() !== false;
        }

        if (! $matched) {
            throw new \RuntimeException('Chat history changed or was deleted; refusing stale snapshot');
        }

        if ($this->loadedVersion !== null) {
            $this->loadedVersion = (string) $stmt->fetchColumn();
        }

        $this->loadedMessages = $parameters['llm_messages'];
        $this->loadedDisplayMessages = $parameters['display_messages'];
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
}
