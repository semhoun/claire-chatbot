<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'chat_history')]
#[ORM\UniqueConstraint(name: 'uk_thread_id', columns: ['thread_id'])]
#[ORM\Index(name: 'idx_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_thread_id', columns: ['thread_id'])]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class ChatHistory
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null {
        get {
            return $this->id;
        }
    } // Doctrine maps BIGINT as string in PHP by default

    // Migration stores a string user_id; we map a relation without enforcing FK at DB level
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user {
        get {
            return $this->user;
        }
        set(User $value) {
            $this->user = $value;
        }
    }

    #[ORM\Column(name: 'thread_id', type: 'string', length: 128, nullable: false)]
    private string $threadId {
        get {
            return $this->threadId;
        }
        set {
            $this->threadId = $value;
        }
    }

    // Messages persisted as TEXT/LONGTEXT in migration
    #[ORM\Column(name: 'messages', type: 'text', nullable: false)]
    private string $messages {
        get {
            return $this->messages;
        }
        set {
            error_log('Setting messages to ' . $value);
            $this->messages = $value;
        }
    }

    // SQLite migration allows NULL; MySQL version marked NOT NULL. Use nullable to keep portability.
    #[ORM\Column(name: 'title', type: 'text', nullable: true)]
    private ?string $title = null {
        get {
            return $this->title;
        }
        set {
            $this->title = $value;
        }
    }

    #[ORM\Column(name: 'summarize', type: 'text', nullable: true)]
    private ?string $summarize = null {
        get {
            return $this->summarize;
        }
        set {
            $this->summarize = $value;
        }
    }

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt {
        get {
            return $this->createdAt;
        }
        set {
            $this->createdAt = $value;
        }
    }

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $updatedAt {
        get {
            return $this->updatedAt;
        }
        set {
            $this->updatedAt = $value;
        }
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
}
