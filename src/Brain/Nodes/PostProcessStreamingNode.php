<?php

declare(strict_types=1);

namespace App\Brain\Nodes;

use App\Brain\Tools\MessagePostProcessorInterface;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Workflow\Events\StopEvent;
use Throwable;

/**
 * Extended StreamingNode that applies post-processing from tools implementing
 * MessagePostProcessorInterface.
 *
 * This node checks if any tools from the last tool call implement the
 * post-processor interface, and if so, applies their post-processing logic
 * to the final assistant message before adding it to chat history.
 */
final class PostProcessStreamingNode extends StreamingNode
{
    private ?ToolCallMessage $lastToolCallMessage = null;

    /**
     * @throws Throwable
     */
    #[\Override]
    public function __invoke(
        AIInferenceEvent $event,
        AgentState $state
    ): Generator|ToolCallEvent {
        $this->addToChatHistory($state, $event->getMessages());

        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        $this->emit('inference-start', new InferenceStart($lastMessage));

        try {
            $stream = $this->provider
                ->systemPrompt($event->instructions)
                ->setTools($event->tools)
                ->stream(...$chatHistory->getMessages());

            // Yield all chunks as-is (TextChunk, ReasoningChunk, etc.)
            foreach ($stream as $chunk) {
                yield $chunk;
            }

            // Get the final message from the generator return value
            $message = $stream->getReturn();

            $this->emit('inference-stop', new InferenceStop($lastMessage, $message));

            // Route based on the message type
            if ($message instanceof ToolCallMessage) {
                $this->lastToolCallMessage = $message;

                return new ToolCallEvent($message, $event);
            }

            // Apply post-processing if we have tools from a previous tool call
            if ($this->lastToolCallMessage instanceof \NeuronAI\Chat\Messages\ToolCallMessage) {
                $message = $this->applyPostProcessing($message);
                $this->lastToolCallMessage = null; // Reset for next cycle
            }

            // Add the final message to the chat history (after tool loop)
            $this->addToChatHistory($state, $message);

            return new StopEvent();
        } catch (Throwable $throwable) {
            $this->emit('error', new AgentError($throwable));

            throw $throwable;
        }
    }

    /**
     * Apply post-processing from tools that implement
     * MessagePostProcessorInterface.
     */
    private function applyPostProcessing(
        \NeuronAI\Chat\Messages\Message $message
    ): \NeuronAI\Chat\Messages\Message {
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
