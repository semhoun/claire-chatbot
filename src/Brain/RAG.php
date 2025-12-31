<?php

declare(strict_types=1);

namespace App\Brain;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Odan\Session\SessionInterface;

class RAG extends \NeuronAI\RAG\RAG
{
    public function __construct(
        protected Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
        protected AIProviderInterface $aiProvider,
        protected EmbeddingsProviderInterface $embeddingsProvider,
        protected VectorStoreInterface $vectorStore,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function chatHistory(): ChatHistoryInterface
    {
        return new UserChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            table: 'chat_history',
            contextWindow: $this->settings->get('llm.history.contextWindow')
        );
    }

    protected function provider(): AIProviderInterface
    {
        return $this->aiProvider;
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->embeddingsProvider;
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return $this->vectorStore;
    }

    /**
     * Define your middleware here.
     *
     * @return array<class-string<NodeInterface>, array<WorkflowMiddleware>>
     */
    protected function middleware(): array
    {
        $summarization = new Summarization(
            provider: $this->aiProvider,
            maxTokens: $this->settings->get('llm.history.contextWindow') / 2,
            messagesToKeep: 10,
        );
        return [
            ChatNode::class => [$summarization],
            StreamingNode::class => [$summarization],
            StructuredOutputNode::class => [$summarization],
        ];
    }
}
