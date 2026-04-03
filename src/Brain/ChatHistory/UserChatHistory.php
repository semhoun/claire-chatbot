<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Brain\Observability\HasInstrumentation;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\AbstractChatHistory;
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

    protected string $user_id;

    protected string $thread_id;

    /**
     * @var array<Message>
     */
    protected array $displayHistory = [];

    public function __construct(
        SessionInterface $session,
        protected PDO $pdo,
        protected int $contextWindow = 50000
    ) {
        $this->user_id = $session->get(Auth::USERID);

        $this->thread_id = $session->get('chatId');
        if ($this->thread_id !== null) {
            $this->load();
        }

        parent::__construct($contextWindow);
    }

    public function setThreadId(string $thread_id): void
    {
        if ($this->thread_id === $thread_id) {
            return;
        }

        $this->thread_id = $thread_id;
        $this->load();
    }

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

    public function getDisplayMessages(): array
    {
        return $this->displayHistory;
    }

    public function removeLastExchange(): ?string
    {
        $lastUserMessage = $this->removeLastExchangeFromMessages($this->history);
        if ($lastUserMessage === null) {
            return null;
        }

        $this->removeLastExchangeFromMessages($this->displayHistory);

        $this->persistHistories();

        return $lastUserMessage->getContent();
    }

    public function getFormattedMessages(string $mode): array
    {
        if (empty($this->displayHistory)) {
            return [];
        }

        $data = [];
        $toolCallId = null;
        $toolText = null;
        foreach ($this->displayHistory as $message) {
            if ($message instanceof ToolCallMessage && $mode === 'stream') {
                $toolCallId = uniqid('tool-', true);
            } elseif ($message instanceof ToolResultMessage) {
                $toolText = '<span class="tools-done-flag" style="display:none"></span>' . "\n";
                $tools = $message->getTools();
                foreach ($tools as $tool) {
                    $toolText .= "Utilisation de l'outil : " . $tool->getName() . "<br>\n";
                    $toolText .= "Paramètres : <br>\n";
                    $toolText .= "<ul>\n";
                    foreach ($tool->getInputs() as $name => $value) {
                        $toolText .= '<li>' . $name . ' : ' . $value . "</li>\n";
                    }

                    $toolText .= "</ul>\n";
                    $toolText .= "Réponse : <br>\n";
                    if ($tool->getResult() !== '' && $tool->getResult() !== '0') {
                        $toolText .= '<pre class="toolcall__result">' . $tool->getResult() . "</pre>\n";
                    }
                }
            } else {
                $content = $message->getContent();
                $timestamp = $message->getMetadata('timestamp');

                $data[] = [
                    'message' => $content ?? '',
                    'time' => $timestamp ?? '',
                    'sent' => $message->getRole() === 'user',
                    'toolCallId' => $toolCallId,
                    'toolText' => $toolText,
                ];
                $toolCallId = null;
                $toolText = null;
            }
        }

        return $data;
    }

    protected function load(): void
    {
        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT %s, %s FROM %s WHERE thread_id = :thread_id',
                self::LLM_MESSAGES_COLUMN,
                self::DISPLAY_MESSAGES_COLUMN,
                self::TABLE
            )
        );
        $stmt->execute(['thread_id' => $this->thread_id]);

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
                'user_id' => $this->user_id,
                'thread_id' => $this->thread_id,
                self::LLM_MESSAGES_COLUMN => '[]',
                self::DISPLAY_MESSAGES_COLUMN => '[]',
                self::DISPLAY_MESSAGES_COUNT_COLUMN => 0,

            ]);
            $this->history = [];
            $this->displayHistory = [];

            return;
        }


        $history = $history[0];

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

        $llmMessages = $this->deserializeMessages($llmPayload);
        $displayMessages = $this->deserializeMessages($displayPayload);

        $this->history = $this->fixMessageSequence($llmMessages);
        $this->displayHistory = $displayMessages;

        if ($this->shouldPersistHistories($llmPayload, $displayPayload)) {
            $this->persistHistories();
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
            'DELETE FROM ' . self::TABLE . ' WHERE thread_id = :thread_id'
        );
        $stmt->execute(['thread_id' => $this->thread_id]);

        $this->history = [];
        $this->displayHistory = [];
    }

    /**
     * @param array<Message> $messages
     */
    private function removeLastExchangeFromMessages(array &$messages): ?UserMessage
    {
        while ($messages !== []) {
            $message = array_pop($messages);

            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                return $message;
            }
        }

        return null;
    }

    private function persistHistories(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET '
            . self::LLM_MESSAGES_COLUMN . ' = :llm_messages, '
            . self::DISPLAY_MESSAGES_COLUMN . ' = :display_messages, '
            . self::DISPLAY_MESSAGES_COUNT_COLUMN . ' = :display_messages_count '
            . 'WHERE thread_id = :thread_id'
        );
        $stmt->execute([
            'thread_id' => $this->thread_id,
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

    private function shouldPersistHistories(array $llmPayload, array $displayPayload): bool
    {
        return count($llmPayload) !== count($this->history)
            || count($displayPayload) !== count($this->displayHistory);
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

        $fixed = [];
        $expectingUser = true;
        $lastToolCallIndex = null;

        foreach ($messages as $message) {
            // Handle ToolResultMessage - must follow ToolCallMessage
            if ($message instanceof ToolResultMessage) {
                if ($lastToolCallIndex === null) {
                    // ToolResult without ToolCall - skip
                    continue;
                }

                $fixed[] = $message;
                $lastToolCallIndex = null;
                $expectingUser = false;

                continue;
            }

            // Handle ToolCallMessage - assistant message that expects tool result
            if ($message instanceof ToolCallMessage) {
                if (! $expectingUser) {
                    $fixed[] = $message;
                    $lastToolCallIndex = array_key_last($fixed);
                    $expectingUser = true;
                }

                continue;
            }

            // Regular messages - check alternation
            $role = $message->getRole();
            if ($role === MessageRole::USER->value && $expectingUser) {
                $fixed[] = $message;
                $expectingUser = false;
            } elseif ($role === MessageRole::ASSISTANT->value && ! $expectingUser) {
                $fixed[] = $message;
                $expectingUser = true;
                $lastToolCallIndex = null;
            }
        }

        // Remove orphaned ToolCallMessage (without corresponding ToolResultMessage)
        if ($lastToolCallIndex !== null) {
            array_splice($fixed, $lastToolCallIndex, 1);
            while ($fixed !== [] && $fixed[array_key_last($fixed)] instanceof UserMessage) {
                array_pop($fixed);
            }
        }

        while ($fixed !== [] && $fixed[array_key_last($fixed)] instanceof UserMessage) {
            array_pop($fixed);
        }

        return $fixed;
    }
}
