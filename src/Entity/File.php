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
    public const string GENERATED_FILE_PATTERN = "/(<(?:a|img)\b[^>]*?(?:href|src)=)?(['\"]?@@GENERATED@@[a-zA-Z0-9_@\-.]*@@['\"]?)((?(1)[^>]*>|))/i";

    public const string GENERATED_PREFIX = '@@GENERATED@@';

    public const string GENERATED_SUFFIX = '@@';

    public const string GENERATED_FOLDER_PREFIX = 'generated';

    public const string FILE_TYPE_IMAGE = 'image';

    public const string FILE_TYPE_PDF = 'pdf';

    public const string FILE_TYPE_UNKNOWN = 'unknown';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint', nullable: false)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: ChatHistory::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'history_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ChatHistory $chatHistory = null;

    #[ORM\Column(name: 'filename', type: 'string', length: 255, nullable: false)]
    private string $filename;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 191, nullable: false)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes', type: 'bigint', nullable: false)]
    private int $sizeBytes = 0;

    #[ORM\Column(name: 'file_id', type: 'string', length: 255, nullable: false)]
    private string $fileId;

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

    public function setSizeBytes(int $size): void
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

    public function getChatHistory(): ?ChatHistory
    {
        return $this->chatHistory;
    }

    public function setChatHistory(?ChatHistory $chatHistory): void
    {
        $this->chatHistory = $chatHistory;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function fileType(): string
    {
        if (str_starts_with($this->mimeType, 'image/')) {
            return self::FILE_TYPE_IMAGE;
        }

        if ($this->mimeType === 'application/pdf') {
            return self::FILE_TYPE_PDF;
        }

        return self::FILE_TYPE_UNKNOWN;
    }

    public function setGeneratedFileData(ChatHistory $chatHistory, string $filename, string $extension, int $fileSize = 0, array $metadata = []): void
    {
        $fileUuid = Uuid::uuid4()->toString();

        match ($extension) {
            'png', 'jpg', 'jpeg', 'gif', 'webp' => $this->mimeType = 'image/' . $extension,
            'pdf' => $this->mimeType = 'application/pdf',
            default => throw new \InvalidArgumentException('Unsupported file extension: ' . $extension),
        };

        $this->chatHistory = $chatHistory;
        $this->user = $chatHistory->getUser();
        $this->filename = $this->sanitizeFilename($filename) . '.' . $extension;
        $this->fileId = self::GENERATED_PREFIX . $fileUuid . self::GENERATED_SUFFIX;
        $this->filePath = self::GENERATED_FOLDER_PREFIX . '/' . $this->user->getId() . '/' . $fileUuid . '.' . $extension;
        $this->sizeBytes = $fileSize;
        $this->metadata = $metadata;
    }

    private function sanitizeFilename(string $filename): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $filename);
        if ($sanitized === '') {
            return 'claire_generated_file';
        }

        return $sanitized !== null ? substr(trim($sanitized), 0, 100) : '';
    }
}
