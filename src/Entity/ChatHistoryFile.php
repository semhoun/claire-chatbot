<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatHistoryFileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'chat_history_file')]
#[ORM\Index(name: 'idx_chf_history', columns: ['history_id'])]
#[ORM\Index(name: 'idx_chf_user', columns: ['user_id'])]
#[ORM\Entity(repositoryClass: ChatHistoryFileRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ChatHistoryFile
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: ChatHistory::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'history_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ChatHistory $chatHistory;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'file_type', type: 'string', length: 32, nullable: false)]
    private string $fileType;

    #[ORM\Column(name: 'file_path', type: 'string', length: 512, nullable: false)]
    private string $filePath;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'metadata', type: 'json', nullable: false)]
    private array $metadata = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable('now');
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getHistory(): ChatHistory
    {
        return $this->chatHistory;
    }

    public function setHistory(ChatHistory $chatHistory): void
    {
        $this->chatHistory = $chatHistory;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getFileType(): string
    {
        return $this->fileType;
    }

    public function setFileType(string $fileType): void
    {
        $this->fileType = $fileType;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): void
    {
        $this->filePath = $filePath;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
