<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\File;
use App\Entity\RagDocument;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Psr\Log\LoggerInterface as Logger;

final readonly class RagService implements RagServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmbeddingsProviderInterface $embeddingsProvider,
        private Settings $settings,
        private Logger $logger,
        private RagUrlFetcher $ragUrlFetcher,
    ) {
    }

    public function createFromFile(File $file, User $user, string $content): RagDocument
    {
        $ragDocument = new RagDocument();
        $ragDocument->setUser($user);
        $ragDocument->setName($file->getFilename());
        $ragDocument->setSourceType(RagDocument::SOURCE_FILE);
        $ragDocument->setSourceId($file->getFileId());

        return $this->indexDocument($ragDocument, $content);
    }

    public function createFromText(User $user, string $name, string $content): RagDocument
    {
        $ragDocument = new RagDocument();
        $ragDocument->setUser($user);
        $ragDocument->setName($name);
        $ragDocument->setSourceType(RagDocument::SOURCE_TEXT);

        return $this->indexDocument($ragDocument, $content);
    }

    public function createFromUrl(User $user, string $name, string $url): RagDocument
    {
        $content = $this->fetchUrlContent($url);
        if ($content === '') {
            throw new \RuntimeException('Impossible de récupérer le contenu de l’URL.');
        }

        $ragDocument = new RagDocument();
        $ragDocument->setUser($user);
        $ragDocument->setName($name);
        $ragDocument->setSourceType(RagDocument::SOURCE_URL);
        $ragDocument->setSourceId($url);

        return $this->indexDocument($ragDocument, $content);
    }

    public function delete(RagDocument $ragDocument): void
    {
        $this->withUserLock($ragDocument->getUser(), function () use ($ragDocument): void {
            $path = $this->documentStorePath($ragDocument);
            if (is_file($path) && ! unlink($path)) {
                throw new \RuntimeException('Unable to delete RAG store: ' . $path);
            }

            $this->entityManager->remove($ragDocument);
            $this->entityManager->flush();
            $this->rebuildActiveStore($ragDocument->getUser());
        });
    }

    public function setActive(RagDocument $ragDocument, bool $active): void
    {
        $this->withUserLock($ragDocument->getUser(), function () use ($ragDocument, $active): void {
            $ragDocument->setIsActive($active);
            $this->entityManager->flush();
            $this->rebuildActiveStore($ragDocument->getUser());
        });
    }

    /**
     * @return array<RagDocument>
     */
    public function listForUser(User $user): array
    {
        return $this->entityManager->getRepository(RagDocument::class)->listByUser($user->getId());
    }

    public function getActiveVectorStoreForUser(User $user): VectorStoreInterface
    {
        return new FileVectorStore(
            directory: $this->userDirectory($user),
            topK: $this->settings->get('llm.rag.topK') ?? 4,
            name: 'active',
        );
    }

    /**
     * @return list<float>
     */
    public function embedQuery(string $query): array
    {
        return $this->embeddingsProvider->embedText($query);
    }

    /**
     * @return list<string>
     */
    public function listSegments(RagDocument $ragDocument): array
    {
        $path = $this->documentStorePath($ragDocument);
        if (! is_file($path)) {
            return [];
        }

        $segments = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($entry) || ! isset($entry['content'])) {
                    continue;
                }

                $segments[] = (string) $entry['content'];
            }
        } finally {
            fclose($handle);
        }

        return $segments;
    }

    public function rebuildActiveVectorStore(User $user): void
    {
        $this->withUserLock($user, fn () => $this->rebuildActiveStore($user));
    }

    private function rebuildActiveStore(User $user): void
    {
        $activeDocuments = $this->entityManager->getRepository(RagDocument::class)
            ->findActiveByUser($user->getId());

        $activePath = $this->userDirectory($user) . '/active.store';
        $tempPath = tempnam(dirname($activePath), '.active-');
        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create RAG temporary store.');
        }

        $handle = fopen($tempPath, 'w');
        if ($handle === false) {
            unlink($tempPath);
            throw new \RuntimeException('Unable to write RAG active store: ' . $tempPath);
        }

        try {
            foreach ($activeDocuments as $activeDocument) {
                if ($activeDocument->getUser()->getId() !== $user->getId()) {
                    throw new \RuntimeException('RAG document owner mismatch.');
                }
                $sourcePath = $this->documentStorePath($activeDocument);
                if (! is_file($sourcePath)) {
                    continue;
                }

                $sourceHandle = fopen($sourcePath, 'r');
                if ($sourceHandle === false) {
                    throw new \RuntimeException('Unable to read RAG store: ' . $sourcePath);
                }

                try {
                    // Copy legacy rows unchanged; no embedding call or format conversion.
                    if (stream_copy_to_stream($sourceHandle, $handle) === false) {
                        throw new \RuntimeException('Unable to copy RAG store: ' . $sourcePath);
                    }
                } finally {
                    fclose($sourceHandle);
                }
            }

            $permissions = is_file($activePath) ? fileperms($activePath) & 0o777 : 0o644 & ~umask();
            if (! fflush($handle) || ! chmod($tempPath, $permissions) || ! rename($tempPath, $activePath)) {
                throw new \RuntimeException('Unable to finalize RAG active store: ' . $activePath);
            }
        } finally {
            fclose($handle);
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        $this->logger->info('RAG active store rebuilt', [
            'user_id' => $user->getId(),
            'documents' => count($activeDocuments),
        ]);
    }

    private function indexDocument(RagDocument $ragDocument, string $content): RagDocument
    {
        $documents = $this->splitContent($content);
        $ragDocument->setChunkCount(count($documents));

        foreach ($documents as $document) {
            $document->setSourceType($ragDocument->getSourceType())
                ->setSourceName($ragDocument->getName());
            if ($ragDocument->getSourceId() !== null) {
                $document->addMetadata('source_id', $ragDocument->getSourceId());
            }
        }
        $embedded = $this->embeddingsProvider->embedDocuments($documents);
        $this->withUserLock($ragDocument->getUser(), function () use ($ragDocument, $embedded): void {
            $this->entityManager->persist($ragDocument);
            $this->entityManager->flush();
            $this->documentVectorStore($ragDocument)->addDocuments($embedded);

            if ($ragDocument->isActive()) {
                $this->rebuildActiveStore($ragDocument->getUser());
            }
        });

        return $ragDocument;
    }

    /**
     * @return array<Document>
     */
    private function splitContent(string $content): array
    {
        return StringDataLoader::for($content)
            ->withSplitter(
                new DelimiterTextSplitter(
                    maxLength: $this->settings->get('llm.rag.chunkSize') ?? 1000,
                    separator: '.',
                    wordOverlap: 0,
                )
            )
            ->getDocuments();
    }

    private function documentVectorStore(RagDocument $ragDocument): VectorStoreInterface
    {
        return new FileVectorStore(
            directory: $this->userDirectory($ragDocument->getUser()),
            name: $ragDocument->getDocumentId(),
        );
    }

    private function documentStorePath(RagDocument $ragDocument): string
    {
        return $this->userDirectory($ragDocument->getUser()) . '/' . $ragDocument->getDocumentId() . '.store';
    }

    private function userDirectory(User $user): string
    {
        if ($user->getId() === '') {
            throw new \RuntimeException('A persisted user is required for RAG storage.');
        }

        return $this->settings->get('llm.rag.path') . '/' . $user->getId();
    }

    private function withUserLock(User $user, \Closure $operation): void
    {
        $directory = $this->userDirectory($user);
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create RAG directory: ' . $directory);
        }

        // Stable across atomic replacements; all document mutations share this lock.
        $lock = fopen($directory . '/.documents.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open RAG user lock.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire RAG user lock.');
            }
            $operation();
        } finally {
            fclose($lock);
        }
    }

    private function fetchUrlContent(string $url): string
    {
        return $this->ragUrlFetcher->fetch($url);
    }
}
