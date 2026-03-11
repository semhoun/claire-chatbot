<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Services\Auth;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use App\Session\SessionInterface;
use PDO;

class UserChatHistory extends SQLChatHistory
{
    protected string $user_id;

    public function __construct(
        SessionInterface $session,
        protected PDO $pdo,
        protected string $table = 'chat_history',
        protected int $contextWindow = 50000
    ) {
        $this->user_id = $session->get(Auth::USERID);

        $thread_id = $session->get('chatId');
        if ($thread_id === null) {
            AbstractChatHistory::__construct($contextWindow);
            $this->table = $this->sanitizeTableName($table);
            return;
        }

        parent::__construct($session->get('chatId'), $pdo, $table, $contextWindow);
    }

    public function setThreadId(string $thread_id): void
    {
        $this->thread_id = $thread_id;
        $this->load();
    }

    #[\Override]
    protected function load(): void
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE thread_id = :thread_id', $this->table));
        $stmt->execute(['thread_id' => $this->thread_id]);

        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($history)) {
            $stmt = $this->pdo->prepare(sprintf('INSERT INTO %s (user_id, thread_id, messages) VALUES (:user_id, :thread_id, :messages)', $this->table));
            $stmt->execute(['user_id' => $this->user_id,'thread_id' => $this->thread_id, 'messages' => '[]']);
        } else {
            $this->history = $this->deserializeMessages(\json_decode((string) $history[0]['messages'], true));
        }
    }
}
