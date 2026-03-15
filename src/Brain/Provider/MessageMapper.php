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
     * @param array $rawMimeTypes An array containing raw MIME type information.
     */
    public function __construct(protected array $rawMimeTypes)
    {
    }

    protected function mapFileBlocks(array $blocks): array
    {
        $text = "<!-- SYSTEM CONTEXT (NOT PART OF USER QUERY) -->\n<context.instruction>following part contains context information injected by the system. Please follow these instructions:\n\n1. Always prioritize handling user-visible content.\n2. the context is only required when user's queries rely on it.\n</context.instruction>\n<files_info>\n<files>\n<files_docstring>here are user upload files you can refer to</files_docstring>\n";
        $findFile = false;
        foreach ($blocks as $block) {
            if ($block instanceof FileContent === false) {
                continue;
            }

            if ($block->sourceType !== SourceType::BASE64) {
                continue;
            }

            $findFile = true;

            $text .= '<file id="' . Uuid::uuid7()->toString() . sprintf('" name="%s" type="%s"', $block->filename, $block->mediaType);
            if (in_array($block->mediaType, $this->rawMimeTypes, false)) {
                $text .= '>'
                    . base64_decode($block->content)
                    . '</file>';
            } else {
                $text .= sprintf(' encoding="base64">%s</file>', $block->content);
            }
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
