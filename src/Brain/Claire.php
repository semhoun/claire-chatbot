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
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use Odan\Session\SessionInterface;

class Claire extends Agent implements BrainAvatar
{
    use ClaireAvatar;

    public function __construct(
        protected Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
        protected readonly AIProviderInterface $aiProvider,
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
            background: [
                "Tu es Claire mon assistant personnel.",
                "Ton rôle est de m’aider à organiser mes idées, planifier mes tâches, et répondre rapidement à mes demandes.",
                "Tu dois être clair, synthétique, proactif.",
            ],
            steps: [],
            output: [
                "Pose-moi toujours une question à la fin pour m’aider à avancer.",
            ]
        );
    }

    protected function provider(): AIProviderInterface
    {
        return $this->aiProvider;
    }

    protected function tools(): array
    {
        // TODO gérer les erreurs
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
            provider: $this->aiProvider,
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
