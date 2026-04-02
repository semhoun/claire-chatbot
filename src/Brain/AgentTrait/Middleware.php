<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Middleware\ShortMemory;
use App\Brain\Middleware\ToolCalls;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;

trait Middleware
{
    #[\Override]
    protected function middleware(): array
    {
        $shortMemory = new ShortMemory(
            logger: $this->logger,
            provider: $this->provider(),
            maxTokens: $this->settings->get('llm.openai.contextWindow') / 2,
            messagesToKeep: 2,
       );

        $toolCalls = new ToolCalls();

        return [
            ChatNode::class => [$shortMemory, $toolCalls],
            StreamingNode::class => [$shortMemory, $toolCalls],
            StructuredOutputNode::class => [$shortMemory, $toolCalls],
        ];
    }
}
