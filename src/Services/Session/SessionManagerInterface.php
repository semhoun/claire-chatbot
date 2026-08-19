<?php

declare(strict_types=1);

namespace App\Services\Session;

interface SessionManagerInterface extends SessionInterface
{
    public function getId(): string;

    /** @return array<string, mixed>|null */
    public function getStorageAsArray(): ?array;
}
