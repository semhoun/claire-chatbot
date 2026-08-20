<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RagDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Table(name: 'rag_document')]
#[ORM\Index(name: 'idx_rag_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_rag_active', columns: ['user_id', 'is_active'])]
#[ORM\Entity(repositoryClass: RagDocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class RagDocument
{
    public const string SOURCE_FILE = 'file';

    public const string SOURCE_TEXT = 'text';

    public const string SOURCE_URL = 'url';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(name: 'document_id', type: 'string', length: 64, unique: true, nullable: false)]
    private string $documentId;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(name: 'source_type', type: 'string', length: 32, nullable: false)]
    private string $sourceType = self::SOURCE_TEXT;

    #[ORM\Column(name: 'source_id', type: 'string', length: 255, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(name: 'is_active', type: 'boolean', nullable: false)]
    private bool $isActive = true;

    #[ORM\Column(name: 'chunk_count', type: 'integer', nullable: false)]
    private int $chunkCount = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable('now');
        $this->updatedAt ??= new \DateTimeImmutable('now');

        if (! isset($this->documentId) || ($this->documentId === '' || $this->documentId === '0')) {
            $this->documentId = Uuid::uuid7()->toString();
        }
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function setDocumentId(string $documentId): void
    {
        $this->documentId = $documentId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): void
    {
        $this->sourceType = $sourceType;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function setSourceId(?string $sourceId): void
    {
        $this->sourceId = $sourceId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getChunkCount(): int
    {
        return $this->chunkCount;
    }

    public function setChunkCount(int $chunkCount): void
    {
        $this->chunkCount = $chunkCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
