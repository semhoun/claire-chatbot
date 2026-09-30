<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\Update\Update;
use Psr\Log\LoggerInterface;

/** No session/conversation lock and no generation queue in this ingress path. */
final readonly class TelegramStopIngress
{
    public function __construct(
        private Connection $connection,
        private ChatStopRequests $stops,
        private Settings $settings,
        private TelegramBotApi $bot,
        private LoggerInterface $logger,
    ) {
    }

    /** Read-only source authentication; never creates or locks a Telegram session. */
    public function accept(Update $update): ?array
    {
        $message = $update->message;
        if (! $this->settings->get('llm.stop.enabled', false) || $message === null || $message->from === null
            || $message->from->isBot || $message->senderChat !== null
            || str_starts_with($message->text ?? '', '/')) {
            return null;
        }
        if ($message->text === null && $message->voice === null && $message->audio === null
            && $message->document === null && ($message->photo === null || $message->photo === [])) {
            return null;
        }
        $userId = $this->connection->fetchOne(
            'SELECT id FROM account WHERE telegram_id = ?',
            [(string) $message->from->id]
        );
        if ($userId === false) {
            return null;
        }
        $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
        $session = $this->connection->fetchOne(
            'SELECT session_data FROM telegram_session WHERE telegram_id = ?',
            [(string) $message->from->id]
        );
        $data = $session === false ? [] : json_decode($session, true, flags: JSON_THROW_ON_ERROR);
        $threadId = is_array($data) && is_string($data['threadId'] ?? null) ? $data['threadId'] : '';
        if ($threadId === '') {
            $threadId = 'telegram' . TelegramJournal::id($botId, 'update:' . $update->updateId);
        }
        return $this->register(
            $botId,
            $update->updateId,
            $userId,
            $threadId,
            $message->chat->id,
            $message->messageThreadId ?? 0
        );
    }

    /** Acceptance must call this BEFORE enqueue and preserve the returned thread binding on retries. */
    public function register(
        string $botId,
        int $updateId,
        string $userId,
        string $threadId,
        int $chatId,
        int $topicId = 0,
    ): array {
        $id = TelegramJournal::id($botId, 'update:' . $updateId);
        try {
            $this->connection->transactional(function () use (
                $id,
                $botId,
                $updateId,
                $userId,
                $threadId,
                $chatId,
                $topicId,
            ): void {
                $this->connection->insert('telegram_stop_target', [
                    'id' => $id, 'bot_id' => $botId, 'update_id' => $updateId, 'user_id' => $userId,
                    'thread_id' => $threadId, 'chat_id' => (string) $chatId, 'topic_id' => $topicId,
                ]);
                $this->stops->register($userId, $threadId, 'telegram', $id);
            });
        } catch (UniqueConstraintViolationException) {
            // First acceptance owns the binding, even after a thread switch.
        }
        $target = $this->connection->fetchAssociative('SELECT * FROM telegram_stop_target WHERE id = ?', [$id]);
        if ($target === false || $target['user_id'] !== $userId || $target['chat_id'] !== (string) $chatId
            || (int) $target['topic_id'] !== $topicId) {
            throw new \RuntimeException('Telegram acceptance identity mismatch');
        }
        return $target;
    }

    /** Returns true for consumed stop commands, including disabled or foreign-bot commands. */
    public function handle(Update $update): bool
    {
        $message = $update->message;
        if ($message === null || preg_match(
            '/\A\/stop(?:@([A-Za-z0-9_]+))?(?:\s|\z)/i',
            $message->text ?? '',
            $match
        ) !== 1) {
            return false;
        }
        if (! $this->settings->get('llm.stop.enabled', false)) {
            return true;
        }
        if (isset($match[1]) && $match[1] !== '' && strcasecmp(
            $match[1],
            ltrim((string) $this->settings->get('telegram.bot_username', ''), '@')
        ) !== 0) {
            return true;
        }
        $sender = $message->from;
        if ($sender === null || $sender->isBot || $message->senderChat !== null) {
            return true;
        }
        $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
        $id = TelegramJournal::id($botId, (string) $update->updateId);
        $scope = hash('sha256', json_encode([$botId, $sender->id, $message->chat->id,
            $message->messageThreadId ?? 0,
        ], JSON_THROW_ON_ERROR));
        $userId = $this->connection->fetchOne(
            'SELECT id FROM account WHERE telegram_id = ?',
            [(string) $sender->id]
        );
        $binding = $this->connection->fetchAssociative('SELECT * FROM telegram_stop_update WHERE id = ?', [$id]);
        if ($binding === false) {
            // Keep the turn join on its canonical primary key, not the unbounded owner history index.
            $target = $userId === false ? false : $this->connection->fetchOne(
                'SELECT t.id FROM telegram_stop_target t JOIN chat_stop_request s'
                . ' ON s.generation_id = t.id AND s.user_id = t.user_id AND s.thread_id = t.thread_id'
                . " AND s.channel = 'telegram' AND s.status = 'accepted'"
                . ' LEFT JOIN chat_turn c ON c.id = t.id'
                . ' WHERE t.bot_id = ? AND t.user_id = ? AND t.chat_id = ? AND t.topic_id = ?'
                . ' AND t.update_id < ? ORDER BY CASE WHEN c.generation_id = t.id'
                . ' AND c.user_id = t.user_id AND c.thread_id = t.thread_id'
                . " AND c.channel = 'telegram' AND c.status = 'running' THEN 0 ELSE 1 END,"
                . ' t.update_id DESC LIMIT 1',
                [$botId, $userId, (string) $message->chat->id, $message->messageThreadId ?? 0, $update->updateId],
            );
            try {
                $this->connection->transactional(fn () => $this->connection->insert('telegram_stop_update', [
                    'id' => $id, 'target_id' => $target === false ? null : $target,
                    'scope_id' => $scope, 'created_at' => time(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Concurrent retry uses the winner's frozen target, including a null target.
            }
            $binding = $this->connection->fetchAssociative('SELECT * FROM telegram_stop_update WHERE id = ?', [$id]);
        }
        if ($binding['scope_id'] !== $scope) {
            return true;
        }
        $result = null;
        if ($binding['target_id'] !== null) {
            $target = $this->connection->fetchAssociative(
                'SELECT * FROM telegram_stop_target WHERE id = ?',
                [$binding['target_id']]
            );
            if ($target !== false && $target['user_id'] === $userId
                && $target['bot_id'] === $botId && $target['chat_id'] === (string) $message->chat->id
                && (int) $target['topic_id'] === ($message->messageThreadId ?? 0)) {
                $result = $this->stops->request($target['user_id'], $target['thread_id'], 'telegram', $target['id']);
            }
        }
        // Claim before the API call: an uncertain notification must never repeat inference or retarget.
        if ($this->connection->executeStatement(
            'UPDATE telegram_stop_update SET notice_claimed = 1 WHERE id = ? AND notice_claimed = 0',
            [$id],
        ) === 1) {
            try {
                $notice = ($result['status'] ?? '') === 'accepted'
                    ? 'Arret demande. Un outil deja lance peut terminer.'
                    : 'Aucune generation active a arreter.';
                $sent = $this->bot->sendMessage(
                    chatId: $message->chat->id,
                    text: $notice,
                    messageThreadId: $message->messageThreadId
                );
                if ($sent instanceof FailResult) {
                    $this->logger->warning('Telegram rejected stop notice');
                }
            } catch (\Throwable $error) {
                $this->logger->warning('Telegram stop notice failed', ['exception' => $error::class]);
            }
        }
        return true;
    }
}
