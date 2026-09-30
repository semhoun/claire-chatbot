<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\ChatHistory\UserChatHistory as History;
use App\Brain\ChatHistory\WorkingChatHistory;
use App\Brain\Middleware\SemanticRecall;
use App\Services\Auth;
use App\Services\SemanticMemoryService;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\MessageStoreInterface;

trait UserChatHistory
{
    protected ?History $userChatHistory = null;

    #[\Override]
    public function for(string $workflowId): static
    {
        $copy = parent::for($workflowId);
        if ($workflowId !== $this->getThreadId()) {
            if ($this->userChatHistory !== null && $copy->messageStore === $this->userChatHistory->messageStore()) {
                $copy->messageStore = null;
            }
            $copy->userChatHistory = null;
        }

        return $copy;
    }

    public function getUserChatHistory(): History
    {
        return $this->userChatHistory ??= new History(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            contextWindow: $this->contextWindow ?? $this->contextWindow(),
            threadId: $this->requireWorkflowId(),
        );
    }

    #[\Override]
    protected function messageStore(): MessageStoreInterface
    {
        return $this->getUserChatHistory()->messageStore();
    }

    #[\Override]
    protected function contextWindow(): int
    {
        return $this->settings->get('llm.openai.contextWindow');
    }

    #[\Override]
    protected function resources(): AgentResources
    {
        $resources = parent::resources();
        $provider = $resources->provider;
        $userId = (string) $this->session->get(Auth::USERID);
        if ($this->settings->get('llm.semanticMemory.enabled', false) === true && $userId !== '') {
            $threadId = $this->requireWorkflowId();
            $provider = new SemanticRecall(
                $provider,
                fn (string $query): string => $this->container->get(SemanticMemoryService::class)->recall(
                    $userId,
                    $threadId,
                    $query,
                    $this->settings->get('llm.semanticMemory.contextBudget', 6000),
                ),
                $this->logger,
            );
        }

        $history = $resources->history;
        if ($this->userChatHistory !== null
            && $this->resolveMessageStore() === $this->userChatHistory->messageStore()) {
            $history = new WorkingChatHistory(
                $this->userChatHistory,
                $this->requireWorkflowId(),
                $this->contextWindow ?? $this->contextWindow(),
            );
        }

        return new AgentResources(
            $provider,
            $history,
            $resources->instructions,
            $resources->tools,
        );
    }
}
