<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
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

            ChatNode::class => [$summarization],

            StreamingNode::class => [$summarization],

            StructuredOutputNode::class => [$summarization],

        ];
    }
}
