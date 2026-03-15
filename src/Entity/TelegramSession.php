<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TelegramSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'telegram_session')]
#[ORM\UniqueConstraint(name: 'uk_telegram_id', columns: ['telegram_id'])]
#[ORM\Index(name: 'idx_telegram_id', columns: ['telegram_id'])]
#[ORM\Entity(repositoryClass: TelegramSessionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class TelegramSession
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null;

    #[ORM\Column(name: 'telegram_id', type: 'string', length: 64, unique: true, nullable: false)]
    private string $telegramId;

    #[ORM\Column(name: 'session_data', type: 'text', nullable: false)]
    private string $sessionData;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->sessionData = json_encode([], JSON_THROW_ON_ERROR);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable('now');
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTelegramId(): string
    {
        return $this->telegramId;
    }

    public function setTelegramId(string $telegramId): void
    {
        $this->telegramId = $telegramId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSessionData(): array
    {
        try {
            $decoded = json_decode($this->sessionData, associative: true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public function setSessionData(array $sessionData): void
    {
        $this->sessionData = json_encode($sessionData, JSON_THROW_ON_ERROR);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
