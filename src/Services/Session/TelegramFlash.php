<?php

declare(strict_types=1);

namespace App\Services\Session;

/**
 * Flash message handler for Telegram sessions.
 * Flash messages are stored in the session data and cleared after being read.
 */
final class TelegramFlash implements FlashInterface
{
    private const string FLASH_KEY = '__flash';

    /**
     * @var array<string, array<int, string>>
     */
    private array $flashMessages = [];

    /**
     * Reference to parent session data array for persisting flash messages.
     * @var array<string, mixed>
     */
    private array $sessionData = [];

    /**
     * @param array<string, mixed> $sessionData
     */
    public function __construct(array &$sessionData)
    {
        // Store reference to session data
        $this->sessionData = &$sessionData;

        // Extract flash messages from session data
        if (isset($sessionData[self::FLASH_KEY]) && is_array($sessionData[self::FLASH_KEY])) {
            $this->flashMessages = $sessionData[self::FLASH_KEY];
            // Clear flash from session data immediately (they will be re-added if not read)
            unset($sessionData[self::FLASH_KEY]);
        }
    }

    public function add(string $type, string $message): void
    {
        $this->flashMessages[$type][] = $message;
        $this->persist();
    }

    /**
     * @param array<int, string> $messages
     */
    public function set(string $type, array $messages): void
    {
        $this->flashMessages[$type] = $messages;
        $this->persist();
    }

    /**
     * @return array<int, string>
     */
    public function get(string $type): array
    {
        if (!isset($this->flashMessages[$type])) {
            return [];
        }

        $messages = $this->flashMessages[$type];
        unset($this->flashMessages[$type]);
        $this->persist();

        return $messages;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        $messages = $this->flashMessages;
        $this->flashMessages = [];
        $this->persist();

        return $messages;
    }

    public function has(string $type): bool
    {
        return isset($this->flashMessages[$type]) && $this->flashMessages[$type] !== [];
    }

    public function clear(): void
    {
        $this->flashMessages = [];
        $this->persist();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function toArray(): array
    {
        return $this->flashMessages;
    }

    /**
     * @param array<string, array<int, string>> $flashData
     */
    public function fromArray(array $flashData): void
    {
        $this->flashMessages = $flashData;
        $this->persist();
    }

    private function persist(): void
    {
        if ($this->flashMessages !== []) {
            $this->sessionData[self::FLASH_KEY] = $this->flashMessages;
        } else {
            unset($this->sessionData[self::FLASH_KEY]);
        }
    }
}
