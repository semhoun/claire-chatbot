<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use NeuronAI\Chat\Messages\Message;

/**
 * Interface for tools that need to modify the final assistant message
 * after execution.
 *
 * This is particularly useful for tools that generate content (e.g., images)
 * and need to ensure their IDs are properly embedded in the final response.
 */
interface MessagePostProcessorInterface
{
    /**
     * Process the assistant's message after tool execution.
     *
     * This method is called after the LLM generates its final response,
     * allowing the tool to modify the message content to include
     * necessary references (e.g., image IDs).
     *
     * @param Message $message The assistant's message to process
     *
     * @return Message The processed message (can be the same instance
     *                 modified or a new instance)
     */
    public function postProcessMessage(Message $message): Message;
}
