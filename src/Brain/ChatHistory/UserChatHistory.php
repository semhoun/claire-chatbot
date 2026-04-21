<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
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

    protected ?string $title = null;

    protected ?string $summary = null;

    /**
     * @var array<Message>
     */
    protected array $displayHistory = [];

    public function __construct(
        protected SessionInterface $session,
        protected PDO $pdo,
        protected int $contextWindow = 50000,
        protected ?string $threadId = null,
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

    /** @return array<Message> */
    public function getDisplayMessages(): array
    {
        return $this->displayHistory;
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
        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT %s, %s, title, summary FROM %s WHERE thread_id = :thread_id',
                self::LLM_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COLUMN,
                self::TABLE
            )
        );
        $stmt->execute(['thread_id' => $this->threadId]);

        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($history)) {
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

            return;
        }

        $history = $history[0];

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
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE thread_id = :threadId'
        );
        $stmt->execute(['thread_id' => $this->threadId]);

        $this->history = [];
        $this->displayHistory = [];
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

    private function persistHistories(): void
    {
        $stmt = $this->pdo->prepare(
            sprintf(
                'UPDATE %s SET %s = :llm_messages, %s = :display_messages, %s = :display_messages_count WHERE thread_id = :thread_id',
                self::TABLE,
                self::LLM_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COUNT_COLUMN
            )
        );
        $stmt->execute([
            'thread_id' => $this->threadId,
            'llm_messages' => json_encode(
                $this->serializeMessages($this->history),
                JSON_THROW_ON_ERROR
            ),
            'display_messages' => json_encode(
                $this->serializeMessages($this->displayHistory),
                JSON_THROW_ON_ERROR
            ),
            'display_messages_count' => count($this->displayHistory),
        ]);
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
