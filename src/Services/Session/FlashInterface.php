<?php

declare(strict_types=1);

namespace App\Services\Session;

interface FlashInterface
{
    public function add(string $key, string $message): void;

    /** @return array<int, string> */
    public function get(string $key): array;

    public function has(string $key): bool;

    public function clear(): void;

    /** @param array<int, string> $messages */
    public function set(string $key, array $messages): void;

    /** @return array<string, array<int, string>> */
    public function all(): array;
}
