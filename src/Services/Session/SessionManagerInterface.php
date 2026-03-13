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

    public function getStorageAsArray(): ?array;

    public function setStorageFromArray(array $data): void;
}
