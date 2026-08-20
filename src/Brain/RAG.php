<?php

declare(strict_types=1);

namespace App\Brain;

use App\Entity\User;
use App\Services\Auth;
use App\Services\RagService;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class RAG extends \NeuronAI\RAG\RAG
{
    use AgentTrait\AIProvider;
    use AgentTrait\UserChatHistory;
    use AgentTrait\Constructor;
    use AgentTrait\Middleware;

    #[\Override]
    public function resolveInstructions(): string
    {
        $instructions = parent::resolveInstructions();
        $dateLine = sprintf(
            "\n\n[Contexte système] Date et heure actuelles : %s\n",
            new \DateTimeImmutable()->format('Y-m-d H:i:s')
        );

        return $instructions . $dateLine;
    }

    #[\Override]
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->container->get(EmbeddingsProviderInterface::class);
    }

    #[\Override]
    protected function vectorStore(): VectorStoreInterface
    {
        $user = $this->currentUser();
        if (! $user instanceof \App\Entity\User) {
            throw new \RuntimeException('No authenticated user for RAG vector store.');
        }

        $ragService = $this->container->get(RagService::class);

        return $ragService->getActiveVectorStoreForUser($user);
    }

    private function currentUser(): ?User
    {
        $userId = (string) $this->session->get(Auth::USERID);
        if ($userId === '') {
            return null;
        }

        return $this->container->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->find($userId);
    }
}
