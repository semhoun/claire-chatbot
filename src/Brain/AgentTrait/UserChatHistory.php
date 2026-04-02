<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use NeuronAI\Chat\History\ChatHistoryInterface;

trait UserChatHistory
{
    #[\Override]
    protected function chatHistory(): ChatHistoryInterface
    {
        return new \App\Brain\ChatHistory\UserChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            contextWindow: $this->settings->get('llm.openai.contextWindow')
        );
    }
}
