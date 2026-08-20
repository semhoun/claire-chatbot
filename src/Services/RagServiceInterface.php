<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\File;
use App\Entity\RagDocument;
use App\Entity\User;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

interface RagServiceInterface
{
    public function createFromFile(File $file, User $user, string $content): RagDocument;

    public function createFromText(User $user, string $name, string $content): RagDocument;

    public function createFromUrl(User $user, string $name, string $url): RagDocument;

    public function delete(RagDocument $ragDocument): void;

    public function setActive(RagDocument $ragDocument, bool $active): void;

    /**
     * @return array<RagDocument>
     */
    public function listForUser(User $user): array;

    public function getActiveVectorStoreForUser(User $user): VectorStoreInterface;

    /**
     * @return list<float>
     */
    public function embedQuery(string $query): array;

    /**
     * @return list<string>
     */
    public function listSegments(RagDocument $ragDocument): array;

    public function rebuildActiveVectorStore(User $user): void;
}
