<?php

declare(strict_types=1);

namespace App\Brain\Middleware;

use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Observability\HasInstrumentation;
use function array_slice;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface as Logger;

class ShortMemory extends Summarization
{
    use HasInstrumentation;

    protected TokenCounter $tokenCounter;

    public function __construct(
        protected Logger $logger,
        protected AIProviderInterface $provider,
        protected int $maxTokens = 50000,
        protected int $messagesToKeep = 5,
        protected ?string $summaryPrompt = null,
    ) {
        $this->tokenCounter = new TokenCounter();

        parent::__construct($provider, $maxTokens, $messagesToKeep, $summaryPrompt);
    }

    #[\Override]
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        // Only apply to ChatNode, StreamingNode, and StructuredOutputNode

        if (! $event instanceof AIInferenceEvent || ! $state instanceof AgentState) {
            return;
        }

        // Summarization disabled

        if ($this->maxTokens <= 0) {
            return;
        }

        $chatHistory = $state->getChatHistory();

        $messages = $chatHistory->getMessages();

        $usage = $this->estimateTokens($messages);

        // Threshold isn't exceeded

        if ($usage <= $this->maxTokens) {
            return;
        }

        // Perform summarization

        $this->summarizeHistory($state, $messages);
    }

    protected function estimateTokens(array $messages): int
    {
        return array_reduce(
            $messages,
            fn (int $carry, Message $message): int => $carry + $this->tokenCounter->count($message),
            0
        );
    }

    /**
     * Summarize chat history by replacing old messages with a summary.
     *
     * @param array<Message> $messages
     */

    #[\Override]

    protected function summarizeHistory(AgentState $state, array $messages): void
    {
        // Find a safe cutoff point

        $cutoffIndex = $this->findSafeCutoffIndex($messages);

        // If no safe cutoff found or not enough messages to summarize, skip

        if ($cutoffIndex === null || $cutoffIndex <= 0) {
            return;
        }

        // Split messages into old (to summarize) and recent (to keep)

        $oldMessages = array_slice($messages, 0, $cutoffIndex);

        $recentMessages = array_slice($messages, $cutoffIndex);

        // Generate summary of old messages

        $summary = $this->generateSummary($oldMessages);

        $userMessage = new UserMessage("[OC]Previous conversation summary:\n\n{$summary}\n[\OC]");

        $userMessage->addMetadata('message_type', 'out_of_context');

        // Create the new message list: summary + recent messages

        $newMessages = [

            $userMessage,

            ...$recentMessages,

        ];

        $chatHistory = $state->getChatHistory();

        if (! $chatHistory instanceof UserChatHistory) {
            $chatHistory->flushAll();

            foreach ($newMessages as $newMessage) {
                $state->getChatHistory()->addMessage($newMessage);
            }

            return;
        }

        $chatHistory->replaceMessages($newMessages);
    }

    /**
     * Find a safe cutoff index that doesn't break tool call sequences.
     *
     *
     *
     * A safe cutoff point is one where we don't separate a tool call message
     *
     * from its corresponding tool result message.
     *
     * @param array<Message> $messages
     *
     * @return int|null Index to cut at (exclusive), or null if no safe cutoff found
     */

    #[\Override]

    protected function findSafeCutoffIndex(array $messages): ?int
    {
        $totalMessages = count($messages);

        $targetCutoff = max(0, $totalMessages - $this->messagesToKeep);

        // If the target cutoff is in the beginning, nothing to summarize

        if ($targetCutoff <= 0) {
            return null;
        }

        // Search backward from the target to find a safe cutoff point

        for ($i = $targetCutoff; $i >= 0; $i--) {
            if ($this->isSafeCutoffPoint($messages, $i)) {
                return $i;
            }
        }

        // No safe cutoff found

        return null;
    }

    /**
     * Check if a given index is a safe cutoff point.
     *
     *
     *
     * A cutoff is safe if:
     *
     * 1. The message at "index" is not a ToolCallMessage (would leave tool call without result)
     *
     * 2. The previous message is not a ToolCallMessage (would separate tool call from result)
     *
     * 3. The message at index is an AssistantMessage
     *
     * 4. The message at index is not a ToolResultMessage (must follow ToolCallMessage)
     *
     * @param array<Message> $messages
     */

    #[\Override]

    protected function isSafeCutoffPoint(array $messages, int $index): bool
    {
        if (! isset($messages[$index])) {
            return false;
        }

        // Check if a message at cutoff index is a ToolCallMessage

        if ($messages[$index] instanceof ToolCallMessage) {
            return false;
        }

        // Check if a message at cutoff index is a ToolResultMessage

        // (ToolResultMessage must follow ToolCallMessage, cannot be first in recentMessages)

        if ($messages[$index] instanceof ToolResultMessage) {
            return false;
        }

        // Check if a previous message is a ToolResultMessage (would be separated from its result)

        if ($index > 0 && isset($messages[$index - 1]) && $messages[$index - 1] instanceof ToolResultMessage) {
            return false;
        }

        // Check if a message at cutoff index is an AssistantMessage

        return $messages[$index]->getRole() === MessageRole::ASSISTANT->value;
    }
}
