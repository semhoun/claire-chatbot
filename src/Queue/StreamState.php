<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Mutable state for stream processing.
 */
final class StreamState
{
    private string $streamedText = '';

    private ?string $toolCallId = null;

    private string $toolText = '';

    private bool $toolPlaceholderPublished = false;

    private bool $assistantStarted = false;

    public function getStreamedText(): string
    {
        return $this->streamedText;
    }

    public function appendStreamedText(string $text): void
    {
        $this->streamedText .= $text;
    }

    public function getToolCallId(): ?string
    {
        return $this->toolCallId;
    }

    public function setToolCallId(?string $toolCallId): void
    {
        $this->toolCallId = $toolCallId;
    }

    public function getToolText(): string
    {
        return $this->toolText;
    }

    public function appendToolText(string $text): void
    {
        $this->toolText .= $text;
    }

    public function isToolPlaceholderPublished(): bool
    {
        return $this->toolPlaceholderPublished;
    }

    public function setToolPlaceholderPublished(bool $published): void
    {
        $this->toolPlaceholderPublished = $published;
    }

    public function isAssistantStarted(): bool
    {
        return $this->assistantStarted;
    }

    public function setAssistantStarted(bool $started): void
    {
        $this->assistantStarted = $started;
    }
}
