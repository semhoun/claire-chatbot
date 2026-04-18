<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'chat_history')]
#[ORM\UniqueConstraint(name: 'uk_thread_id', columns: ['thread_id'])]
#[ORM\Index(name: 'idx_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_thread_id', columns: ['thread_id'])]
#[ORM\Entity(repositoryClass: ChatHistoryRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ChatHistory
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null; // Doctrine maps BIGINT as string in PHP by default

    // Migration stores a string user_id; we map a relation without enforcing FK at DB level
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'thread_id', type: 'string', length: 128, nullable: false)]
    private string $threadId;

    // Messages persisted as TEXT/LONGTEXT in migration
    #[ORM\Column(name: 'messages', type: 'text', nullable: false)]
    private string $messages;

    #[ORM\Column(name: 'display_messages', type: 'text', nullable: false)]
    private string $displayMessages;

    #[ORM\Column(name: 'display_messages_count', type: 'integer', nullable: false)]
    private int $displayMessagesCount = 0;

    // SQLite migration allows NULL; MySQL version marked NOT NULL. Use nullable to keep portability.
    #[ORM\Column(name: 'title', type: 'text', nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'summary', type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $updatedAt;

    /** @var \Doctrine\Common\Collections\Collection<int, ChatHistoryFile> */
    #[ORM\OneToMany(targetEntity: ChatHistoryFile::class, mappedBy: 'history', cascade: ['remove'])]
    private \Doctrine\Common\Collections\Collection $files;

    public function __construct()
    {
        $this->files = new \Doctrine\Common\Collections\ArrayCollection();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }

    public function setThreadId(string $threadId): void
    {
        $this->threadId = $threadId;
    }

    public function getMessages(): string
    {
        return $this->messages;
    }

    public function setMessages(string $messages): void
    {
        $this->messages = $messages;
    }

    public function getDisplayMessages(): string
    {
        return $this->displayMessages;
    }

    public function setDisplayMessages(string $displayMessages): void
    {
        $this->displayMessages = $displayMessages;
    }

    public function getDisplayMessagesCount(): int
    {
        return $this->displayMessagesCount;
    }

    public function setDisplayMessagesCount(int $displayMessagesCount): void
    {
        $this->displayMessagesCount = $displayMessagesCount;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
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

    /** @return \Doctrine\Common\Collections\Collection<int, ChatHistoryFile> */
    public function getFiles(): \Doctrine\Common\Collections\Collection
    {
        return $this->files;
    }

    public function addFile(ChatHistoryFile $chatHistoryFile): void
    {
        if (! $this->files->contains($chatHistoryFile)) {
            $this->files->add($chatHistoryFile);
            $chatHistoryFile->setHistory($this);
        }
    }
}
