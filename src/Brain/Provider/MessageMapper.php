<?php

declare(strict_types=1);

namespace App\Brain\Provider;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use Ramsey\Uuid\Uuid;

class MessageMapper extends \NeuronAI\Providers\OpenAI\MessageMapper
{
    /**
     * Constructor method to initialize the object with the provided raw MIME type data.
     *
     * @param array<int, string> $rawMimeTypes An array containing raw MIME type information.
     */
    public function __construct(protected array $rawMimeTypes)
    {
    }

    /**
     * Map file blocks to text content.
     *
     * @param array<int, mixed> $blocks
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapFileBlocks(array $blocks): array
    {
        $fileContents = $this->extractFileContents($blocks);

        if ($fileContents === []) {
            return [];
        }

        $text = $this->buildFileContextHeader();
        foreach ($fileContents as $fileContent) {
            $text .= $this->formatFileBlock($fileContent);
        }

        $text .= "</files>\n</files_info>";

        return [['type' => 'text', 'text' => $text]];
    }

    #[\Override]
    /**
     * @param array<int, mixed> $blocks
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapBlocks(array $blocks): array
    {
        $data = $this->mapFileBlocks($blocks);
        foreach ($blocks as &$block) {
            switch ($block::class) {
                case TextContent::class:
                    $data[] = [
                        'type' => 'text',
                        'text' => $block->content,
                    ];
                    break;
                case ImageContent::class:
                    $data[] = $this->mapImageBlock($block);

                    break;
                default:
                    break;
            }
        }

        return $data;
    }

    /**
     * @param array<int, mixed> $blocks
     *
     * @return array<int, FileContent>
     */
    private function extractFileContents(array $blocks): array
    {
        $files = [];
        foreach ($blocks as $block) {
            if ($block instanceof FileContent && $block->sourceType === SourceType::BASE64) {
                $files[] = $block;
            }
        }

        return $files;
    }

    private function buildFileContextHeader(): string
    {
        return <<<'HTML'
<!-- SYSTEM CONTEXT (NOT PART OF USER QUERY) -->
<context.instruction>following part contains context information injected by the system. Please follow these instructions:

1. Always prioritize handling user-visible content.
2. the context is only required when user's queries rely on it.
</context.instruction>
<files_info>
<files>
<files_docstring>here are user upload files you can refer to</files_docstring>
HTML;
    }

    private function formatFileBlock(FileContent $fileContent): string
    {
        $fileId = Uuid::uuid7()->toString();
        $content = in_array($fileContent->mediaType, $this->rawMimeTypes, false)
            ? base64_decode($fileContent->content)
            : sprintf(' encoding="base64">%s', $fileContent->content);

        return sprintf('<file id="%s" name="%s" type="%s">%s</file>', $fileId, $fileContent->filename, $fileContent->mediaType, $content);
    }
}
