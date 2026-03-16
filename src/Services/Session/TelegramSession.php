<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Entity\TelegramSession as TelegramSessionEntity;
use App\Repository\TelegramSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Database-backed session for Telegram users.
 *
 * Each Telegram user has exactly one session stored in the database,
 *
 * identified by their telegram_id.
 */

final class TelegramSession implements SessionInterface
{
    private const string FLASH_KEY = '__flash';

    private ?TelegramSessionEntity $telegramSessionEntity = null;

    private ?string $telegramId = null;

    /**
     * @var array<string, mixed>
     */
    private array $storage = [];

    private bool $loaded = false;

    private ?TelegramFlash $telegramFlash = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Load session data from database.
     */
    public function load(string $telegramId): void
    {
        $this->clear();
        $this->telegramId = $telegramId;

        $telegramSessionRepository = $this->getRepository();
        $this->telegramSessionEntity = $telegramSessionRepository->findOrCreateByTelegramId($this->telegramId);
        $this->storage = $this->telegramSessionEntity->getSessionData();
        $this->loaded = true;

        // Initialize flash handler with storage reference
        $this->telegramFlash = new TelegramFlash($this->storage);
    }

    /**
     * Persist session data to database.
     */
    public function save(): void
    {
        $this->ensureLoaded();

        if (! $this->telegramSessionEntity instanceof \App\Entity\TelegramSession || ! $this->loaded) {
            return;
        }

        // Ensure flash data is included in storage

        if ($this->telegramFlash instanceof \App\Services\Session\TelegramFlash) {
            $flashData = $this->telegramFlash->toArray();

            if ($flashData !== []) {
                $this->storage[self::FLASH_KEY] = $flashData;
            } else {
                unset($this->storage[self::FLASH_KEY]);
            }
        }

        $this->telegramSessionEntity->setSessionData($this->storage);

        $this->entityManager->flush();
    }

    public function flush()
    {
        $this->ensureLoaded();
        $this->save();
        $this->clear();
        $this->loaded = false;
        $this->telegramSessionEntity = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();

        return $this->storage[$key] ?? $default;
    }

    public function all(): array
    {
        $this->ensureLoaded();

        // Return all data except flash messages
        $data = $this->storage;
        unset($data[self::FLASH_KEY]);
        return $data;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureLoaded();
        $this->storage[$key] = $value;
        $this->save();
    }

    public function setValues(array $values): void
    {
        $this->ensureLoaded();

        foreach ($values as $key => $value) {
            $this->storage[$key] = $value;
        }
    }

    public function has(string $key): bool
    {
        $this->ensureLoaded();
        return array_key_exists($key, $this->storage);
    }

    public function delete(string $key): void
    {
        $this->ensureLoaded();

        unset($this->storage[$key]);
    }

    public function clear(): void
    {
        $this->storage = [];

        if ($this->telegramFlash instanceof \App\Services\Session\TelegramFlash) {
            $this->telegramFlash->clear();
        }
    }

    public function getFlash(): FlashInterface
    {
        $this->ensureLoaded();

        if (! $this->telegramFlash instanceof \App\Services\Session\TelegramFlash) {
            $this->telegramFlash = new TelegramFlash($this->storage);
        }

        return $this->telegramFlash;
    }

    private function ensureLoaded(): void
    {
        if (! $this->loaded) {
            throw new Exception\SessionException(
                'TelegramSession not loaded. Call load() first with a telegram_id.'
            );
        }
    }

    private function getRepository(): TelegramSessionRepository
    {
        $entityRepository = $this->entityManager->getRepository(TelegramSessionEntity::class);

        if (! $entityRepository instanceof TelegramSessionRepository) {
            throw new Exception\SessionException(
                'Expected TelegramSessionRepository but got ' . $entityRepository::class
            );
        }

        return $entityRepository;
    }
}
