<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Entity\User;
use App\Services\Auth;
use App\Services\RagServiceInterface;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Psr\Log\LoggerInterface;

class RagSearchTool extends Tool
{
    public function __construct(
        private readonly RagServiceInterface $ragService,
        private readonly EntityManagerInterface $entityManager,
        private readonly SessionInterface $session,
        private readonly LoggerInterface $logger,
    ) {
        $description = <<<EOT
Search through the user's active RAG documents to find relevant information.
Use this tool when the user asks a question that may be answered by the documents they have indexed (uploaded files, pasted text, or URLs).
The tool returns the most relevant excerpts from the active documents.
EOT;

        parent::__construct(
            'rag_search',
            $description,
            [
                new ToolProperty(
                    name: 'query',
                    type: \NeuronAI\Tools\PropertyType::STRING,
                    description: 'The search query to find relevant document excerpts.',
                    required: true,
                ),
            ]
        );
    }

    public function __invoke(string $query): string
    {
        try {
            $user = $this->currentUser();
            if (! $user instanceof \App\Entity\User) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'No authenticated user.',
                ], JSON_THROW_ON_ERROR);
            }

            $documents = $this->ragService->listForUser($user);
            $activeDocuments = array_filter($documents, static fn (\App\Entity\RagDocument $ragDocument): bool => $ragDocument->isActive());
            if ($activeDocuments === []) {
                return json_encode([
                    'status' => 'info',
                    'message' => 'No active RAG documents.',
                    'results' => [],
                ], JSON_THROW_ON_ERROR);
            }

            $store = $this->ragService->getActiveVectorStoreForUser($user);
            $embedding = $this->ragService->embedQuery($query);
            $results = $store->similaritySearch($embedding);

            $excerpts = [];
            foreach ($results as $result) {
                if (! $result instanceof Document) {
                    continue;
                }

                $excerpts[] = [
                    'source' => $result->getSourceName(),
                    'content' => $result->getContent(),
                    'score' => $result->getScore(),
                ];
            }

            return json_encode([
                'status' => 'success',
                'results' => $excerpts,
            ], JSON_THROW_ON_ERROR);
        } catch (\Exception $exception) {
            $this->logger->error('RAG search failed', [
                'exception' => $exception,
                'query' => $query,
            ]);

            return json_encode([
                'status' => 'error',
                'message' => 'RAG search failed: ' . $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    private function currentUser(): ?User
    {
        $userId = (string) $this->session->get(Auth::USERID);
        if ($userId === '') {
            return null;
        }

        return $this->entityManager->getRepository(User::class)->find($userId);
    }
}
