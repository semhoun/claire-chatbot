<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Session advisory locks survive commits made by the agent, but not a lost connection. */
final class ChatThreadLock
{
    private bool $locked = false;

    private readonly string $driver;

    private string $key;

    /** @var resource|null */
    private mixed $fileLock = null;

    public function __construct(private readonly PDO $pdo, string $userId, string $threadId)
    {
        if ($userId === '' || $threadId === '') {
            throw new \InvalidArgumentException('Authenticated user and thread are required');
        }

        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->key = json_encode([$userId, $threadId], JSON_THROW_ON_ERROR);
        if ($this->driver === 'sqlite') {
            // SQLite is single-host; server databases use connection-owned locks below.
            $path = sys_get_temp_dir() . '/claire-chat-'
                . hash('sha256', json_encode([$userId, $threadId], JSON_THROW_ON_ERROR)) . '.lock';
            $file = fopen($path, 'c');
            if ($file === false) {
                throw new RuntimeException('Cannot open chat thread lock');
            }

            if (! flock($file, LOCK_EX | LOCK_NB)) {
                fclose($file);
                throw new RuntimeException('Chat thread is busy');
            }

            $this->fileLock = $file;
            return;
        }

        $sql = match ($this->driver) {
            'pgsql' => 'SELECT pg_try_advisory_lock(hashtextextended(:key, 0))',
            'mysql' => 'SELECT GET_LOCK(:key, 0)',
            default => throw new RuntimeException('Unsupported chat lock database driver'),
        };
        if ($this->driver === 'mysql') {
            // MySQL limits lock names to 64 characters, regardless of identifier length.
            $this->key = hash('sha256', 'claire:chat:' . $this->key);
        }

        $statement = $pdo->prepare($sql);
        $statement->execute(['key' => $this->key]);
        if (! in_array($statement->fetchColumn(), [true, 't', 1, '1'], true)) {
            throw new RuntimeException('Chat thread is busy');
        }

        $this->locked = true;
    }

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if (is_resource($this->fileLock)) {
            flock($this->fileLock, LOCK_UN);
            fclose($this->fileLock);
            $this->fileLock = null;
        }

        if (! $this->locked) {
            return;
        }

        $this->locked = false;
        $statement = $this->pdo->prepare($this->driver === 'mysql'
            ? 'SELECT RELEASE_LOCK(:key)'
            : 'SELECT pg_advisory_unlock(hashtextextended(:key, 0))');
        $statement->execute(['key' => $this->key]);
    }
}
