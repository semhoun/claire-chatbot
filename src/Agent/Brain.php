<?php

declare(strict_types=1);

namespace App\Agent;

use App\Services\Settings;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;

class Brain extends Agent
{
    private Settings $settings;

    public function config(Settings $settings): void
    {
        $this->settings = $settings;
    }

    #[\Override]
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are a friendly AI Agent named Claire and created by Nathanaël SEMHOUN.'],
        );
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAILike(
            baseUri: $this->settings->get('llm.openai.baseUri'),
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.model')
        );
    }

    protected function tools(): array
    {
        return [
            CalculatorToolkit::make(),
            CalendarToolkit::make(),
            Tools\WebToolkit::make($this->settings->get('llm.tools.searchXngGUrl')),
        ];
    }
}
