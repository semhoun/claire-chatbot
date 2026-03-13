<?php

declare(strict_types=1);

namespace App\Services\Session;

interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function all(): array;

    public function set(string $key, mixed $value): void;

    public function setValues(array $values): void;

    public function has(string $key): bool;

    public function delete(string $key): void;

    public function clear(): void;

    public function getFlash(): FlashInterface;
}
