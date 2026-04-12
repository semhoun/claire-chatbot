<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

final class MessageSequenceFixer
{
    /** @var array<int, mixed> */
    private array $fixed = [];

    private bool $expectingUser = true;

    private ?int $lastToolCallIndex = null;

    /**
     * @param array<int, mixed> $messages
     *
     * @return array<int, mixed>
     */
    public function fix(array $messages): array
    {
        foreach ($messages as $message) {
            $this->processMessage($message);
        }

        $this->cleanupOrphanedToolCalls();
        $this->removeTrailingUserMessages();

        return $this->fixed;
    }

    private function processMessage(mixed $message): void
    {
        if ($message instanceof ToolResultMessage) {
            $this->processToolResult($message);
        } elseif ($message instanceof ToolCallMessage) {
            $this->processToolCall($message);
        } else {
            $this->processRegularMessage($message);
        }
    }

    private function processToolResult(ToolResultMessage $toolResultMessage): void
    {
        if ($this->lastToolCallIndex === null) {
            return;
        }

        $this->fixed[] = $toolResultMessage;
        $this->lastToolCallIndex = null;
        $this->expectingUser = false;
    }

    private function processToolCall(ToolCallMessage $toolCallMessage): void
    {
        if ($this->expectingUser) {
            return;
        }

        $this->fixed[] = $toolCallMessage;
        $this->lastToolCallIndex = array_key_last($this->fixed);
        $this->expectingUser = true;
    }

    private function processRegularMessage(mixed $message): void
    {
        $role = $message->getRole();

        if ($role === MessageRole::USER->value && $this->expectingUser) {
            $this->fixed[] = $message;
            $this->expectingUser = false;
        } elseif ($role === MessageRole::ASSISTANT->value && ! $this->expectingUser) {
            $this->fixed[] = $message;
            $this->expectingUser = true;
            $this->lastToolCallIndex = null;
        }
    }

    private function cleanupOrphanedToolCalls(): void
    {
        if ($this->lastToolCallIndex === null) {
            return;
        }

        array_splice($this->fixed, $this->lastToolCallIndex, 1);

        while ($this->fixed !== [] && $this->isUserMessage(end($this->fixed))) {
            array_pop($this->fixed);
        }
    }

    private function removeTrailingUserMessages(): void
    {
        while ($this->fixed !== [] && $this->isUserMessage(end($this->fixed))) {
            array_pop($this->fixed);
        }
    }

    private function isUserMessage(mixed $message): bool
    {
        return $message instanceof UserMessage && ! $message instanceof ToolResultMessage;
    }
}
