<?php

declare(strict_types=1);

namespace App\Agent\ChatHistory;

use NeuronAI\Chat\History\SQLChatHistory;
use Odan\Session\SessionInterface;
use PDO;

class UserChatHistory extends SQLChatHistory
{
    protected string $user_id;

    public function __construct(SessionInterface $session, PDO $pdo, string $table = 'chat_history', int $contextWindow = 50000)
    {
        $this->user_id = $session->get('userId');
        parent::__construct($session->get('chatId'), $pdo, $table, $contextWindow);
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
