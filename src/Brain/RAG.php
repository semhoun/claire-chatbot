<?php

declare(strict_types=1);

namespace App\Brain;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class RAG extends \NeuronAI\RAG\RAG
{
    use AgentTrait\AIProvider;
    use AgentTrait\UserChatHistory;
    use AgentTrait\Constructor;
    use AgentTrait\Middleware;

    #[\Override]
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new \NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings(
            baseUri: $this->settings->get('llm.openai.baseUri') . '/embeddings',
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.modelEmbed')
        );
    }

    #[\Override]
    protected function vectorStore(): VectorStoreInterface
    {
        return new \NeuronAI\RAG\VectorStore\FileVectorStore(
            directory: $this->settings->get('llm.rag.path'),
            name: 'neuron-rag',
        );
    }
}
