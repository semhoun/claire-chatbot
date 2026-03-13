<?php

declare(strict_types=1);

namespace App\Services\Session;

final class ArraySession implements SessionInterface, SessionManagerInterface
{
    private const string FLASH_KEY = '__flash';

    private string $sessionId = '';

    private bool $started = false;

    /**
     * @var array<string, mixed>
     */
    private array $storage = [];

    private readonly ArrayFlash $arrayFlash;

    /**
     * The Singleton's instance is stored in a static field.
     */
    private static ?self $arraySession = null;

    /**
     * The Singleton's constructor should always be private to prevent direct
     * construction calls with the `new` operator.
     */
    private function __construct()
    {
        $this->arrayFlash = new ArrayFlash();
    }

    /**
     * Singletons should not be cloneable.
     */
    private function __clone()
    {
    }

    /**
     * This is the static method that controls the access to the singleton
     * instance. On the first run, it creates a singleton object and places it
     * into the static field. On subsequent runs, it returns the client existing
     * object stored in the static field.
     *
     * This implementation lets you subclass the Singleton class while keeping
     * just one instance of each subclass around.
     */
    public static function getInstance(): self
    {
        if (is_null(self::$arraySession)) {
            self::$arraySession = new ArraySession();
        }

        return self::$arraySession;
    }

    /**
     * Get all session data including flash messages for JWT encoding.
     *
     * @return null|array<string, mixed>
     */
    public function getStorageAsArray(): ?array
    {
        if ($this->started === false) {
            return null;
        }

        $data = $this->storage;
        $flashData = $this->arrayFlash->toArray();

        if ($flashData !== []) {
            $data[self::FLASH_KEY] = $flashData;
        }

        if ($data === []) {
            return null;
        }

        return $data;
    }

    /**
     * Restore session data from JWT decoding, including flash messages.
     *
     * @param array<string, mixed> $data
     */
    public function setStorageFromArray(array $data): void
    {
        if (isset($data[self::FLASH_KEY]) && is_array($data[self::FLASH_KEY])) {
            $this->arrayFlash->fromArray($data[self::FLASH_KEY]);
            unset($data[self::FLASH_KEY]);
        }

        $this->storage = $data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->storage;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;
    }

    public function setValues(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->storage[$key] = $value;
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function delete(string $key): void
    {
        unset($this->storage[$key]);
    }

    public function clear(): void
    {
        $this->storage = [];
    }

    public function getFlash(): FlashInterface
    {
        return $this->arrayFlash;
    }

    public function start(): void
    {
        if ($this->sessionId === '') {
            $this->regenerateId();
        }

        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function regenerateId(): void
    {
        $this->sessionId = $this->generateSessionId();
    }

    public function destroy(): void
    {
        $this->clear();
        $this->sessionId = '';
        $this->started = false;
    }

    public function getId(): string
    {
        return $this->sessionId;
    }

    public function setId(string $id): void
    {
        $this->sessionId = $id;
    }

    public function getName(): string
    {
        return 'array_session';
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
