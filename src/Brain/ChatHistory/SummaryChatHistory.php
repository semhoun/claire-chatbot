<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

class SummaryChatHistory extends UserChatHistory
{
    #[\Override]
    /** @param array<Message> $messages */
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
        return match (true) {
            $content === null => null,
            is_string($content) => $this->deserializeStringContent($content),
            is_array($content) => $this->deserializeArrayContent($content),
            default => new TextContent((string) $content),
        };
    }

    private function deserializeStringContent(string $content): TextContent
    {
        $json = json_decode($content, true);
        if ($json !== null && $json !== false) {
            return $this->deserializeContent($json);
        }

        return new TextContent($content);
    }

    /**
     * @param array<mixed> $content
     */
    private function deserializeArrayContent(array $content): array|null
    {
        if ($content === []) {
            return null;
        }

        $textBlocks = $this->extractTextBlocks($content);

        return $textBlocks === [] ? null : $textBlocks;
    }

    /**
     * @param array<mixed> $content
     *
     * @return array<int, TextContent>
     */
    private function extractTextBlocks(array $content): array
    {
        $blocks = [];

        foreach ($content as $block) {
            if (! isset($block['type'])) {
                return [];
            }

            if (ContentBlockType::from($block['type']) === ContentBlockType::TEXT) {
                $blocks[] = new TextContent(content: $block['content']);
            }
        }

        return $blocks;
    }
}
