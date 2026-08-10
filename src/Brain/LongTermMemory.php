<?php

declare(strict_types=1);

namespace App\Brain;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use Doctrine\DBAL\Connection;

final readonly class LongTermMemory
{
    public const string SESSION_KEY = 'long_term_memory_enabled';

    public function __construct(
        private Connection $connection,
        private SessionInterface $session,
        private int $maxCharacters = 4000,
        private int $updateEveryUserMessages = 5,
    ) {
    }

    public function shouldEvolve(int $userMessageCount): bool
    {
        return $this->session->get(self::SESSION_KEY, false) === true
            && $userMessageCount > 0
            && $userMessageCount % max(1, $this->updateEveryUserMessages) === 0;
    }

    public function recall(): string
    {
        if ($this->session->get(self::SESSION_KEY, false) !== true) {
            return '';
        }

        $userId = (string) $this->session->get(Auth::USERID, '');
        if ($userId === '') {
            return '';
        }

        $memory = $this->connection->fetchOne(
            'SELECT content FROM long_term_memory WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return mb_substr((string) ($memory ? $memory : ''), 0, max(0, $this->maxCharacters));
    }

    public function store(string $content): void
    {
        if ($this->session->get(self::SESSION_KEY, false) !== true) {
            return;
        }

        $userId = (string) $this->session->get(Auth::USERID, '');
        $content = trim(mb_substr($content, 0, max(0, $this->maxCharacters)));
        if ($userId === '' || $content === '') {
            return;
        }

        $updated = $this->connection->update('long_term_memory', [
            'content' => $content,
            'updated_at' => new \DateTimeImmutable(),
        ], ['user_id' => $userId], ['updated_at' => 'datetime_immutable']);

        if ($updated === 0) {
            $this->connection->insert('long_term_memory', [
                'user_id' => $userId,
                'content' => $content,
                'updated_at' => new \DateTimeImmutable(),
            ], ['updated_at' => 'datetime_immutable']);
        }
    }

    public function replace(string $content): void
    {
        $userId = (string) $this->session->get(Auth::USERID, '');
        if ($userId === '') {
            return;
        }

        $this->connection->transactional(function () use ($userId, $content): void {
            $this->connection->delete('long_term_memory', ['user_id' => $userId]);
            $this->store($content);
        });
    }
}
