<?php

declare(strict_types=1);

namespace App\Brain;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use Odan\Session\SessionInterface;

class Claire extends Agent
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
        return new UserChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            table: 'chat_history',
            contextWindow: $this->settings->get('llm.history.contextWindow')
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

    /**
     * Define your middleware here.
     *
     * @return array<class-string<NodeInterface>, array<WorkflowMiddleware>>
     */
    protected function middleware(): array
    {
        $summarization = new Summarization(
            provider: new OpenAILike(
                baseUri: $this->settings->get('llm.openai.baseUri'),
                key: $this->settings->get('llm.openai.key'),
                model: $this->settings->get('llm.openai.modelSummary')
            ),
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
