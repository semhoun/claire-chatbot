<?php

declare(strict_types=1);

namespace App\Brain\Nodes;

use App\Brain\Tools\MessagePostProcessorInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Workflow\Events\StopEvent;

/**
 * Extended ChatNode that applies post-processing from tools implementing
 * MessagePostProcessorInterface.
 *
 * This node checks if any tools from the last tool call implement the
 * post-processor interface, and if so, applies their post-processing logic
 * to the final assistant message before adding it to chat history.
 */
final class PostProcessChatNode extends ChatNode
{
    private ?ToolCallMessage $lastToolCallMessage = null;

    #[\Override]
    public function __invoke(
        AIInferenceEvent $event,
        AgentState $state
    ): StopEvent|ToolCallEvent {
        $this->addToChatHistory($state, $event->getMessages());

        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        $this->emit('inference-start', new \NeuronAI\Observability\Events\InferenceStart($lastMessage));
        $response = $this->inference($event, $chatHistory->getMessages());
        $this->emit('inference-stop', new \NeuronAI\Observability\Events\InferenceStop($lastMessage, $response));

        // If the response is a tool call, store it and route to the tool node
        if ($response instanceof ToolCallMessage) {
            $this->lastToolCallMessage = $response;

            return new ToolCallEvent($response, $event);
        }

        // Apply post-processing if we have tools from a previous tool call
        if ($this->lastToolCallMessage instanceof \NeuronAI\Chat\Messages\ToolCallMessage) {
            $response = $this->applyPostProcessing($response);
            $this->lastToolCallMessage = null; // Reset for next cycle
        }

        // Add the final response to chat history (after tool loop)
        $this->addToChatHistory($state, $response);

        return new StopEvent();
    }

    /**
     * Apply post-processing from tools that implement MessagePostProcessorInterface.
     */
    private function applyPostProcessing(\NeuronAI\Chat\Messages\Message $message): \NeuronAI\Chat\Messages\Message
    {
        if (! $this->lastToolCallMessage instanceof \NeuronAI\Chat\Messages\ToolCallMessage) {
            return $message;
        }

        foreach ($this->lastToolCallMessage->getTools() as $tool) {
            if ($tool instanceof MessagePostProcessorInterface) {
                $message = $tool->postProcessMessage($message);
            }
        }

        return $message;
    }
}
