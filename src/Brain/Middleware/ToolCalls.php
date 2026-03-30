<?php

declare(strict_types=1);

namespace App\Brain\Middleware;

use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Tools\MessagePostProcessorInterface;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class ToolCalls implements WorkflowMiddleware
{
    private ?ToolCallMessage $lastToolCallMessage = null;

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if ($result instanceof ToolCallEvent) {
            $result->toolCallMessage->addMetadata('message_type', 'tool_call');

            $this->lastToolCallMessage = $result->toolCallMessage;
        }

        if ($result instanceof StopEvent && $this->lastToolCallMessage instanceof \NeuronAI\Chat\Messages\ToolCallMessage) {
            $chatHistory = $state->getChatHistory();

            if (! $chatHistory instanceof UserChatHistory) {
                return;
            }

            $messages = $chatHistory->getMessages();

            $lastMessage = array_pop($messages);

            $messages[] = $this->applyPostProcessing($lastMessage);

            $chatHistory->replaceMessages($messages);
        }
    }

    /**
     * Apply post-processing from tools that implement
     *
     * MessagePostProcessorInterface.
     */

    private function applyPostProcessing(
        \NeuronAI\Chat\Messages\Message $message
    ): \NeuronAI\Chat\Messages\Message {
        foreach ($this->lastToolCallMessage->getTools() as $tool) {
            if ($tool instanceof MessagePostProcessorInterface) {
                $message = $tool->postProcessMessage($message);
            }
        }

        return $message;
    }
}
