<?php

declare(strict_types=1);

namespace App\Session;

use App\Session\FlashInterface;

/**
 * Flash messages stored in memory with JWT serialization support.
 *
 * Messages are marked as consumed after being read (read-once behavior).
 */
final class ArrayFlash implements FlashInterface
{
    /** @var array<string, array<int, string>> */
    private array $messages = [];

    /** @var array<string, bool> */
    private array $consumed = [];

    public function add(string $key, string $message): void
    {
        if (! isset($this->messages[$key])) {
            $this->messages[$key] = [];
        }

        $this->messages[$key][] = $message;
        $this->consumed[$key] = false;
    }

    public function get(string $key): array
    {
        if (! isset($this->messages[$key])) {
            return [];
        }

        $messages = $this->messages[$key];
        unset($this->messages[$key], $this->consumed[$key]);

        return $messages;
    }

    public function has(string $key): bool
    {
        return isset($this->messages[$key]) && ! $this->consumed[$key];
    }

    public function clear(): void
    {
        $this->messages = [];
        $this->consumed = [];
    }

    public function set(string $key, array $messages): void
    {
        $this->messages[$key] = $messages;
        $this->consumed[$key] = false;
    }

    public function all(): array
    {
        $all = [];

        foreach ($this->messages as $key => $messages) {
            $all[$key] = $messages;
        }

        $this->messages = [];
        $this->consumed = [];

        return $all;
    }

    /**
     * Get all flash data for JWT serialization.
     *
     * @return array<string, array<int, string>>
     */
    public function toArray(): array
    {
        return $this->messages;
    }

    /**
     * Load flash data from JWT deserialization.
     *
     * @param array<string, array<int, string>> $data
     */
    public function fromArray(array $data): void
    {
        $this->messages = $data;
        $this->consumed = [];

        foreach (array_keys($data) as $key) {
            $this->consumed[$key] = false;
        }
    }
}
