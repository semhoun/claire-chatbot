<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Nodes\PostProcessChatNode;
use App\Brain\Nodes\PostProcessStreamingNode;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\StructuredOutputNode;

trait Middleware
{
    #[\Override]
    protected function middleware(): array
    {
        $summarization = new Summarization(
            provider: $this->provider(),
            maxTokens: $this->settings->get('llm.openai.contextWindow') / 2,
            messagesToKeep: 10,
        );

        return [
            PostProcessChatNode::class => [$summarization],
            PostProcessStreamingNode::class => [$summarization],
            StructuredOutputNode::class => [$summarization],
        ];
    }
}
