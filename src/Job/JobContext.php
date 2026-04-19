<?php

declare(strict_types=1);

namespace App\Job;

use App\Services\Session\InMemorySession;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Context object for WebChatMessageJob to avoid passing too many parameters.
 */
final class JobContext
{
    private string $finalContent = '';

    public function __construct(
        public readonly string $chatId,
        public readonly string $sessionId,
        public readonly string $messageId,
        public readonly string $messageArticleId,
        public readonly string $timestamp,
        public readonly InMemorySession $inMemorySession,
        public readonly mixed $agent,
        public readonly UserMessage $userMessage,
    ) {
    }

    public function setFinalContent(string $content): void
    {
        $this->finalContent = $content;
    }

    public function getFinalContent(): string
    {
        return $this->finalContent;
    }
}
