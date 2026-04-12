<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Middleware\ShortMemory;
use App\Brain\Middleware\ToolCalls;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\HttpClient\GuzzleHttpClient;

trait Middleware
{
    #[\Override]
    /** @return array<class-string, array<object>> */
    protected function middleware(): array
    {
        $openAI = new \App\Brain\Provider\OpenAI(
            baseUri: $this->settings->get('llm.openai.baseUri'),
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.modelSummary'),
            rawMimeTypes: $this->settings->get('llm.rawMimeTypes'),
            httpClient: new GuzzleHttpClient(customHeaders: [], timeout: $this->settings->get('llm.httpClient.timeout'), connectTimeout: $this->settings->get('llm.httpClient.connectTimeout'))
        );
        $shortMemory = new ShortMemory(
            logger: $this->logger,
            provider: $openAI,
            maxTokens: $this->settings->get('llm.shortMemory.maxTokens'),
            messagesToKeep: $this->settings->get('llm.shortMemory.messageToKeep'),
        );

        $toolCalls = new ToolCalls();

        return [
            ChatNode::class => [$toolCalls, $shortMemory],
            StreamingNode::class => [$toolCalls, $shortMemory],
            StructuredOutputNode::class => [$toolCalls, $shortMemory],
        ];
    }
}
