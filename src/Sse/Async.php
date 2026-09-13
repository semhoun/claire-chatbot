<?php

declare(strict_types=1);

namespace App\Sse;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

final class Async
{
    public static function timeout(PromiseInterface $promise, LoopInterface $loop, float $seconds): PromiseInterface
    {
        $timer = null;
        return new Promise(static function ($resolve, $reject) use ($promise, $loop, $seconds, &$timer): void {
            $timer = $loop->addTimer($seconds, static function () use ($promise, $reject): void {
                $reject(new \RuntimeException('SSE dependency timeout'));
                $promise->cancel();
            });
            $promise->then(static function ($value) use ($resolve, $loop, &$timer): void {
                $loop->cancelTimer($timer);
                $resolve($value);
            }, static function (\Throwable $error) use ($reject, $loop, &$timer): void {
                $loop->cancelTimer($timer);
                $reject($error);
            });
        }, static function () use ($promise, $loop, &$timer): void {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            $promise->cancel();
            throw new \RuntimeException('SSE operation cancelled');
        });
    }
}
