<?php

declare(strict_types=1);

namespace App\Agent;

use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use Odan\Session\SessionInterface;

class Brain extends Agent
{
    public function __construct(
        protected Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function chatHistory(): ChatHistoryInterface
    {
        return new ChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            table: 'chat_history',
            contextWindow: 50000
        );
    }

    #[\Override]
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [$this->settings->get('llm.brain.systemPrompt')],
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
            Tools\WebToolkit::make($this->settings->get('llm.tools.searchXngUrl')),
        ];
    }
}
