<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FileRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Table(name: 'file')]
#[ORM\Index(name: 'idx_cf_user_id', columns: ['user_id'])]
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\HasLifecycleCallbacks]
class File
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(name: 'filename', type: 'string', length: 255, nullable: false)]
    private string $filename;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 191, nullable: false)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes', type: 'bigint', nullable: false)]
    private string $sizeBytes = '0';

    #[ORM\Column(name: 'file_id', type: 'string', length: 36, nullable: false)]
    private string $fileId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'token', type: 'string', length: 36, unique: true, nullable: false)]
    private ?string $token = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable('now');
        // Ensure token is set (UUID v7)
        if ($this->token === null || ($this->token === '' || $this->token === '0')) {
            $this->token = Uuid::uuid7()->toString();
        }

        if (! isset($this->fileId) || ($this->fileId === '' || $this->fileId === '0')) {
            $this->fileId = Uuid::uuid7()->toString();
        }
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

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }

    public function getSizeBytes(): string
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(string $size): void
    {
        $this->sizeBytes = $size;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function setFileId(string $fileId): void
    {
        $this->fileId = $fileId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }
}
