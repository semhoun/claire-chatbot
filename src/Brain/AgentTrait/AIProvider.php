<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;

trait AIProvider
{
    #[\Override]
    protected function provider(): AIProviderInterface
    {
        return new \App\Brain\Provider\OpenAI(
            baseUri: $this->settings->get('llm.openai.baseUri'),
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.model'),
            rawMimeTypes: $this->settings->get('llm.rawMimeTypes'),
            httpClient: new GuzzleHttpClient(customHeaders: [], timeout: $this->settings->get('llm.httpClient.timeout'), connectTimeout: $this->settings->get('llm.httpClient.connectTimeout'))
        );
    }
}
