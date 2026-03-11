<?php

declare(strict_types=1);

namespace App\Brain;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Agent\Middleware\Summarization;
use App\Session\SessionInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;

class Agent extends \NeuronAI\Agent\Agent
{
    public function __construct(
        protected Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
        protected readonly AIProviderInterface $aiProvider,
        protected readonly UserChatHistory $chatHistory,
    ) {
        parent::__construct();
        $this->observe(new \App\Brain\Observability\Observer());
    }

    #[\Override]
    protected function chatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory;
    }

    #[\Override]
    protected function provider(): AIProviderInterface
    {
        return $this->aiProvider;
    }

    /**
     * Define your middleware here.
     *
     * @return array<class-string<NodeInterface>, array<WorkflowMiddleware>>
     */
    #[\Override]
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
