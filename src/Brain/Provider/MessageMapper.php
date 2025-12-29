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
    protected function mapFileBlocks(array $blocks): array
    {
        $text = "<!-- SYSTEM CONTEXT (NOT PART OF USER QUERY) -->\n"
            . "<context.instruction>following part contains context information injected by the system. Please follow these instructions:\n\n"
            . "1. Always prioritize handling user-visible content.\n"
            . "2. the context is only required when user's queries rely on it.\n"
            . "</context.instruction>\n"
            . "<files_info>\n"
            . "<files>\n"
            . "<files_docstring>here are user upload files you can refer to</files_docstring>\n";
        $findFile = false;
        foreach ($blocks as $block) {
            if ($block instanceof FileContent === false) {
                continue;
            }

            if ($block->sourceType !== SourceType::BASE64) {
                continue;
            }

            $findFile = true;
            $text .= '<file'
                            . ' id="' . Uuid::uuid7()->toString() . '"'
                            . ' name="' . $block->filename . '"'
                            . ' type="' . $block->mediaType . '"'
                            . '>'
                            . $block->content
                            . '</file>';
        }

        if ($findFile === false) {
            return [];
        }

        $text .= "</files>\n</files_info>";
        return [[
            'type' => 'text',
            'text' => $text,
        ],
        ];
    }

    #[\Override]
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
}
