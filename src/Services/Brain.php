<?php

declare(strict_types=1);

namespace App\Services;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tools\Tool;

class Brain extends Agent
{
    private Settings $settings;

    public function config(Settings $settings): void
    {
        $this->settings = $settings;
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAILike (
            baseUri: $this->settings->get('llm.openai.baseUri'),
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.model')
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ["You are a friendly AI Agent named Claire and created by Nathanaël SEMHOUN."],
        );
    }

    protected function tools(): array
    {
        return [
            Tool::make(
                'get_date_time',
                'Retrieve the current date time in the format ISO8601.',
            )->setCallable(function () {
                return date(DATE_ISO8601);
            })
        ];
    }
}
