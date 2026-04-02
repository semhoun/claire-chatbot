<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use PDO;

/**
 * Based on NeuronAI\Chat\History\SQLChatHistory.
 */
class UserChatHistory extends AbstractChatHistory
{
    public const string TABLE = 'chat_history';

    public const string CHAT_WEB = 'ordinary';

    public const string CHAT_TELEGRAM = 'telegram';

    protected string $user_id;

    protected string $thread_id;

    public function __construct(
        SessionInterface $session,
        protected PDO $pdo,
        protected int $contextWindow = 50000
    ) {
        $this->user_id = $session->get(Auth::USERID);

        $this->thread_id = $session->get('chatId', null);
        if ($this->thread_id !== null) {
            $this->load();
        }

        parent::__construct($contextWindow);
    }

    public function setThreadId(string $thread_id): void
    {
        $this->thread_id = $thread_id;
        $this->load();
    }

    public function replaceMessages(array $messages): void
    {
        $this->setMessages($messages);
    }

    public function getFormattedMessages(string $mode, ?array $messages = null): array
    {
        if ($messages === null) {
            $messages = $this->history;
        }

        $data = [];
        $toolCallId = null;
        $toolText = null;
        foreach ($messages as $message) {
            if ($message->getMetadata('message_type') === 'out_of_context') {
                continue;
            }

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
                    'message' => $content === null ? '' : $content,
                    'time' => $timestamp === null ? '' : $timestamp,
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
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE thread_id = :thread_id', self::TABLE));
        $stmt->execute(['thread_id' => $this->thread_id]);

        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($history)) {
            $stmt = $this->pdo->prepare(sprintf('INSERT INTO %s (user_id, thread_id, messages) VALUES (:user_id, :thread_id, :messages)', self::TABLE));
            $stmt->execute(['user_id' => $this->user_id,'thread_id' => $this->thread_id, 'messages' => '[]']);
        } else {
            $this->history = $this->deserializeMessages(\json_decode((string) $history[0]['messages'], true));
        }
    }

    protected function setMessages(array $messages): void
    {
        $this->history = $messages;

        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET messages = :messages WHERE thread_id = :thread_id'
        );
        $stmt->execute([
            'thread_id' => $this->thread_id,
            'messages' => json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR),
        ]);
    }

    protected function clear(): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE thread_id = :thread_id'
        );
        $stmt->execute(['thread_id' => $this->thread_id]);
    }
}
