<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Nodes\PostProcessChatNode;
use App\Brain\Nodes\PostProcessStreamingNode;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Trait to configure Agent nodes with post-processing support.
 *
 * This trait overrides the default chat() and stream() methods to use
 * custom nodes that support message post-processing from tools.
 */
trait Nodes
{
    /**
     * @param Message|array<Message> $messages
     */
    #[\Override]
    public function chat(
        Message|array $messages = [],
        ?InterruptRequest $interruptRequest = null
    ): AgentHandler {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        // Use our custom ChatNode with post-processing support
        $this->compose(
            new PostProcessChatNode($this->resolveProvider()),
        );

        return new AgentHandler($this, $interruptRequest);
    }

    /**
     * Stream agent response with post-processing support.
     *
     * @param Message|array<Message> $messages
     */
    #[\Override]
    public function stream(
        Message|array $messages = [],
        ?InterruptRequest $interruptRequest = null
    ): AgentHandler {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        // Use our custom StreamingNode with post-processing support
        $this->compose(
            new PostProcessStreamingNode($this->resolveProvider()),
        );

        return new AgentHandler($this, $interruptRequest);
    }
}
