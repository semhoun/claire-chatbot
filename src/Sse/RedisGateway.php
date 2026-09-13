<?php

declare(strict_types=1);

namespace App\Sse;

use React\Promise\PromiseInterface;

interface RedisGateway
{
    public function ready(): bool;

    /** @param callable(string): void $listener */
    public function subscribe(string $channel, string $id, callable $listener): PromiseInterface;

    public function unsubscribe(string $channel, string $id): void;

    /** @return PromiseInterface<array<string, string>> */
    public function generation(string $userId, string $threadId): PromiseInterface;

    /** @return PromiseInterface<array<string, mixed>|null> */
    public function remember(string $id): PromiseInterface;
}
