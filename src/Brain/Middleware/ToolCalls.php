<?php

declare(strict_types=1);

namespace App\Brain\Middleware;

use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Tools\MessagePostProcessorInterface;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

final class ToolCalls implements WorkflowMiddleware
{
    public function __construct(private readonly ?UserChatHistory $history = null)
    {
    }

    public function before(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        WorkflowResources $resources,
    ): void {
        if (! $event instanceof AgentOutputEvent || ! $state instanceof AgentState
            || ! $resources instanceof AgentResources || $state->getMessage() === null) {
            return;
        }
        $message = $state->getMessage();
        if ($message->getMetadata('stop_reason') === \NeuronAI\HttpClient\StoppableHttpClient::STOP_REASON
            || $message->getMetadata('generation_stopped') === true) {
            return;
        }
        $processed = [];
        foreach ($state->getSteps() as $step) {
            if (! $step instanceof ToolResultMessage) {
                continue;
            }
            foreach ($step->getToolCalls() as $call) {
                $tool = $resources->tools->find($call->getName());
                if ($tool instanceof MessagePostProcessorInterface && $call->hasResult()
                    && ! isset($processed[$call->getCallId()])) {
                    $message = $tool->postProcessMessage($message, $call);
                    $processed[$call->getCallId()] = true;
                }
            }
        }
        if ($processed !== []) {
            $this->history?->updateMessage($message);
            // Keep the original response envelope and its provider metadata intact.
            $original = $state->getMessage();
            if ($message !== $original) {
                $original->setContents($message->getContentBlocks());
                $original->setMetadata($message->jsonSerialize()['__meta']);
            }
        }
    }

    public function after(
        NodeInterface $node,
        Event $result,
        WorkflowState $state,
        WorkflowResources $resources,
    ): void {
        if ($result instanceof ToolCallEvent) {
            $result->toolCallMessage->addMetadata('message_type', 'tool_call');
        }
    }
}
