<?php

declare(strict_types=1);

namespace App\Services\Session;

use RuntimeException;

final class InMemorySession implements SessionInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /** @param array<string, mixed> $values */
    public function setValues(array $values): void
    {
        $this->values = [...$this->values, ...$values];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function getFlash(): FlashInterface
    {
        throw new RuntimeException('Flash not available in queue session');
    }
}
