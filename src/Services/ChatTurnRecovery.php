<?php

declare(strict_types=1);

namespace App\Services;

use App\Job\Web\NewMessageJob;
use App\Services\Queue\QueueMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class ChatTurnRecovery
{
    private string $cursor = '';

    public function __construct(
        private readonly Connection $connection,
        private readonly ChatStreamPublisher $publisher,
        private readonly TelegramGeneration $telegramGeneration,
        private readonly TelegramService $telegramService,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Bounded traversal also revisits terminal SQL results after a projection/delivery outage.
     *
     * @return array{counts: array<string, int>, cursor: string}
     */
    public function recover(int $limit = 100, bool $apply = true, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Invalid recovery limit');
        }
        if ($cursor !== null) {
            if (strlen($cursor) > 128) {
                throw new \InvalidArgumentException('Invalid recovery cursor');
            }
            $this->cursor = $cursor === '0' ? '' : $cursor;
        }
        $journal = new ChatTurnJournal($this->connection);
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM chat_turn WHERE id > ? ORDER BY id LIMIT ' . $limit,
            [$this->cursor],
        );
        $counts = [];
        foreach ($ids as $id) {
            try {
                $result = $apply ? $this->recoverTurn($journal->get($id)) : 'eligible';
            } catch (ChatGenerationBusyException) {
                $result = 'locked';
            } catch (\Throwable $error) {
                $this->logger->error('Chat turn recovery failed', ['turnId' => $id, 'exception' => $error]);
                $result = 'failed';
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }
        $this->cursor = count($ids) === $limit ? end($ids) : '';
        return ['counts' => $counts, 'cursor' => $this->cursor === '' ? '0' : $this->cursor];
    }

    /** @param array<string, mixed> $turn */
    public function recoverTurn(array $turn): string
    {
        $locks = [];
        try {
            if ($turn['channel'] === 'telegram') {
                $sessionId = (string) ($turn['notification']['sessionId'] ?? '');
                if ($sessionId === '') {
                    return 'missing-destination';
                }
                $locks[] = new ChatThreadLock($this->connection->getNativeConnection(), 'telegram-session', $sessionId);
                $locks[] = new ChatThreadLock($this->connection->getNativeConnection(), 'telegram-journal', $turn['id']);
            }
            $locks[] = new ChatThreadLock($this->connection->getNativeConnection(), $turn['userId'], $turn['threadId']);
            $journal = $this->journalForTurn($turn);
            $turn = $journal->rollback($turn['id'], $turn['userId']);
            if (($turn['deletedAt'] ?? null) !== null) {
                return 'deleted';
            }
            try {
                $this->project($turn);
            } catch (\Throwable $error) {
                $this->logger->error('Chat recovery projection failed', ['exception' => $error]);
            }
            if ($turn['channel'] === 'telegram' && $turn['status'] === 'rolled_back') {
                $record = new TelegramJournal($this->connection)->load($turn['id']);
                $botId = explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0];
                if (($record['botId'] ?? null) === $botId && isset($turn['notification']['chatId'])) {
                    $this->telegramGeneration->notifyFailure(
                        $turn['id'],
                        fn () => $this->telegramService->sendFailureNotice((int) $turn['notification']['chatId'])
                    );
                }
            }
            return $turn['status'];
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** Called only after the queue has confirmed its terminal transition. */
    public function failed(QueueMessage $job): void
    {
        if (in_array($job->jobClass, [TelegramService::class, \App\Job\Telegram\StartThreadJob::class], true)) {
            $this->telegramService->failed($job->payload);
            return;
        }
        if ($job->jobClass !== NewMessageJob::class) {
            return;
        }
        $payload = $job->payload;
        $id = (string) ($payload['messageId'] ?? '');
        $user = (string) ($payload['session'][Auth::USERID] ?? '');
        $thread = (string) ($payload['threadId'] ?? '');
        if ($id === '' || $user === '' || $thread === '') {
            return;
        }
        $lock = new ChatThreadLock($this->connection->getNativeConnection(), $user, $thread);
        try {
            $stops = $this->registeredStops(['userId' => $user, 'threadId' => $thread,
                'channel' => 'web', 'generationId' => $id,
            ]);
            $journal = new ChatTurnJournal($this->connection, $stops);
            $turn = $journal->get($id);
            if ($turn === null) {
                $state = $this->publisher->generationState()->get($user, $thread);
                if (($state['messageId'] ?? '') !== $id || ($state['status'] ?? '') === 'deleted'
                    || ($state['attempted'] ?? '0') === '1') {
                    return;
                }
                $journal->begin(
                    $id,
                    $user,
                    $thread,
                    'web',
                    $id,
                    $payload['submissionId'] ?? null,
                    ['sessionId' => ChatStreamSubscriber::scope($user, (string) $payload['sessionId'])]
                );
                $text = is_string($payload['message'] ?? null) ? trim($payload['message']) : '';
                if ($text !== '' && $stops?->isRequested($user, $thread, 'web', $id)) {
                    $this->project($journal->complete($id, $user, 'stopped', function () use (
                        $payload,
                        $text,
                        $id,
                        $user,
                        $thread,
                    ): void {
                        $message = new \NeuronAI\Chat\Messages\UserMessage($text)
                            ->addMetadata('timestamp', gmdate(DATE_ATOM))
                            ->addMetadata('claire_submission_id', $payload['submissionId'] ?? 'user-' . hash('sha256', $id));
                        new \App\Brain\ChatHistory\UserChatHistory(
                            new \App\Services\Session\InMemorySession([Auth::USERID => $user]),
                            $this->connection->getNativeConnection(),
                            threadId: $thread,
                            createIfMissing: false,
                        )->persistStoppedTurn([$message], $id, $id);
                    }));
                    return;
                }
            }
            $this->project($journal->rollback($id, $user));
        } finally {
            $lock->release();
        }
    }

    /**
     * Caller holds the conversation lock. Unknown Redis generations may still be queued for entry.
     *
     * @param array<string, mixed> $turn
     * @param array<string, mixed> $previous
     */
    public static function canProjectTerminal(Connection $connection, array $turn, array $previous): bool
    {
        if (($turn['deletedAt'] ?? null) !== null || ($previous['status'] ?? '') === 'deleted'
             || ! in_array($turn['status'], ['succeeded', 'stopped', 'rolled_back'], true)
            || $connection->fetchOne(
                'SELECT id FROM chat_turn WHERE user_id = ? AND thread_id = ? ORDER BY history_revision DESC LIMIT 1',
                [$turn['userId'], $turn['threadId']],
            ) !== $turn['id']) {
            return false;
        }
        if ($previous === [] || ($previous['messageId'] ?? '') === $turn['id']) {
            return true;
        }
        $older = new ChatTurnJournal($connection)->get((string) ($previous['messageId'] ?? ''));
        return $older !== null && $older['userId'] === $turn['userId'] && $older['threadId'] === $turn['threadId']
            && $older['historyRevision'] < $turn['historyRevision']
            && in_array($older['status'], ['succeeded', 'stopped', 'rolled_back'], true);
    }

    /** @param array<string, mixed> $turn */
    private function project(array $turn): void
    {
        $state = $this->publisher->generationState();
        $previous = $state->get($turn['userId'], $turn['threadId']);
        if (! self::canProjectTerminal($this->connection, $turn, $previous)) {
            return;
        }
        $state->set(
            $turn['userId'],
            $turn['threadId'],
            $turn['id'],
            match ($turn['status']) {
                'succeeded' => 'done', 'stopped' => 'stopped', default => 'error'
            },
            true
        );
        if ($turn['channel'] === 'web' && $turn['status'] === 'stopped'
            && isset($turn['notification']['sessionId'])) {
            $this->publisher->publish($turn['notification']['sessionId'], 'chat.assistant.stopped', [
                'threadId' => $turn['threadId'], 'sessionId' => $turn['notification']['sessionId'],
                'messageId' => $turn['id'], 'submissionId' => $turn['submissionId'], 'turnStatus' => 'stopped',
            ]);
        }
        if ($turn['channel'] === 'web' && $turn['status'] === 'rolled_back'
            && isset($turn['notification']['sessionId'])) {
            $this->publisher->publish($turn['notification']['sessionId'], 'chat.error', [
                'threadId' => $turn['threadId'], 'sessionId' => $turn['notification']['sessionId'],
                'messageId' => $turn['id'], 'submissionId' => $turn['submissionId'],
                'turnStatus' => 'rolled_back', 'rollbackConfirmed' => true,
                'message' => 'Désolé, une erreur est survenue lors du traitement de votre message.',
            ]);
        }
    }

    /** @param array<string, mixed> $turn */
    private function journalForTurn(array $turn): ChatTurnJournal
    {
        return new ChatTurnJournal($this->connection, $this->registeredStops($turn));
    }

    /** @param array<string, mixed> $turn */
    private function registeredStops(array $turn): ?ChatStopRequests
    {
        $stops = null;
        if ($this->settings->get('llm.stop.enabled', false) === true) {
            // Legacy/welcome turns have no accepted stop identity, even when the feature is enabled now.
            $id = hash('sha256', json_encode([$turn['userId'], $turn['threadId'],
                $turn['channel'], $turn['generationId'],
            ], JSON_THROW_ON_ERROR));
            if ($this->connection->fetchOne('SELECT id FROM chat_stop_request WHERE id = ?', [$id]) === $id) {
                $stops = new ChatStopRequests($this->connection);
            }
        }
        return $stops;
    }
}
