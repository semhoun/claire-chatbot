<?php

declare(strict_types=1);

namespace App\Session;

interface FlashInterface
{
    public function add(string $key, string $message): void;

    public function get(string $key): array;

    public function has(string $key): bool;

    public function clear(): void;

    public function set(string $key, array $messages): void;

    public function all(): array;
}
