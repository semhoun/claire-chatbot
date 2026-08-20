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
        $path = $this->documentStorePath($ragDocument);
        if (is_file($path)) {
            unlink($path);
        }

        $this->entityManager->remove($ragDocument);
        $this->entityManager->flush();
        $this->rebuildActiveVectorStore($ragDocument->getUser());
    }

    public function setActive(RagDocument $ragDocument, bool $active): void
    {
        $ragDocument->setIsActive($active);
        $this->entityManager->flush();
        $this->rebuildActiveVectorStore($ragDocument->getUser());
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
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }

                $entry = json_decode($line, true);
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
        $activeDocuments = $this->entityManager->getRepository(RagDocument::class)
            ->findActiveByUser($user->getId());

        $activePath = $this->userDirectory($user) . '/active.store';
        $tempPath = $activePath . '.tmp';

        $directory = dirname($activePath);
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true)) {
            throw new \RuntimeException('Unable to create RAG directory: ' . $directory);
        }

        $handle = fopen($tempPath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Unable to write RAG active store: ' . $tempPath);
        }

        try {
            foreach ($activeDocuments as $activeDocument) {
                $sourcePath = $this->documentStorePath($activeDocument);
                if (! is_file($sourcePath)) {
                    continue;
                }

                $sourceHandle = fopen($sourcePath, 'r');
                if ($sourceHandle === false) {
                    continue;
                }

                while (($line = fgets($sourceHandle)) !== false) {
                    fwrite($handle, $line);
                }

                fclose($sourceHandle);
            }
        } finally {
            fclose($handle);
        }

        if (is_file($activePath)) {
            unlink($activePath);
        }

        if (! rename($tempPath, $activePath)) {
            throw new \RuntimeException('Unable to finalize RAG active store: ' . $activePath);
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

        $this->entityManager->persist($ragDocument);
        $this->entityManager->flush();

        $embedded = $this->embeddingsProvider->embedDocuments($documents);
        $vectorStore = $this->documentVectorStore($ragDocument);
        $vectorStore->addDocuments($embedded);

        if ($ragDocument->isActive()) {
            $this->rebuildActiveVectorStore($ragDocument->getUser());
        }

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
        return $this->settings->get('llm.rag.path') . '/' . $user->getId();
    }

    private function fetchUrlContent(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'ClaireBot/1.0',
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        return is_string($content) ? trim($content) : '';
    }
}
