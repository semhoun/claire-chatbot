<?php

declare(strict_types=1);

namespace App\Services\Session;

interface SessionManagerInterface
{
    public function start(): void;

    public function isStarted(): bool;

    public function regenerateId(): void;

    public function destroy(): void;

    public function getId(): string;

    public function getName(): string;

    /** @return array<string, mixed>|null */
    public function getStorageAsArray(): ?array;

    /** @param array<string, mixed> $data */
    public function setStorageFromArray(array $data): void;
}
