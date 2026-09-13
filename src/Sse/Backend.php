<?php

declare(strict_types=1);

namespace App\Sse;

use React\Promise\PromiseInterface;

interface Backend
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function request(string $operation, array $payload): PromiseInterface;

    public function close(string $authorization): void;
}
