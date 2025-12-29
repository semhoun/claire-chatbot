<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

class SummaryChatHistory extends UserChatHistory
{
    #[\Override]
    protected function setMessages(array $messages): void
    {
    }

    #[\Override]
    protected function clear(): void
    {
    }

    #[\Override]
    protected function deserializeContent(mixed $content): string|ContentBlockInterface|array|null
    {
        if ($content === null) {
            return null;
        }

        // Legacy format: simple string - convert to TextContent for migration
        if (is_string($content)) {
            if ($json = json_decode($content, true)) {
                return $this->deserializeContent($json);
            }

            return new TextContent($content);
        }

        // New format: array of content blocks
        if (is_array($content)) {
            // Empty array
            if ($content === []) {
                return null;
            }

            $data = [];
            foreach ($content as $block) {
                if (! isset($block['type'])) {
                    return null;
                }

                if (ContentBlockType::from($block['type']) !== ContentBlockType::TEXT) {
                    continue;
                }

                $data[] = new TextContent(content: $block['content']);
            }

            if ($data === []) {
                return null;
            }

            return $data;
        }

        // Fallback: treat as string and convert to TextContent
        return new TextContent((string) $content);
    }
}
