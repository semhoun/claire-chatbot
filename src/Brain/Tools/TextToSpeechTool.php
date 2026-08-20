<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Services\Audio\AudioServiceInterface;
use App\Services\AudioGeneratorService;
use App\Services\Session\SessionInterface;
use JsonException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class TextToSpeechTool extends Tool implements MessagePostProcessorInterface
{
    public function __construct(
        private readonly AudioGeneratorService $audioGeneratorService,
        AudioServiceInterface $audioService,
        private readonly SessionInterface $session,
        private readonly string $threadId,
    ) {
        $voices = implode(', ', array_map(
            static fn (array $voice): string => sprintf(
                '%s (%s)',
                $voice['id'],
                $voice['label'],
            ),
            $audioService->voices(),
        ));
        $description = <<<EOT
Generates a spoken MP3 audio file from text and sends it to the user.
Use this tool whenever the user asks for text-to-speech, narration, a spoken version, or an audio recording of text.
Available voices: {$voices}. Omit the voice parameter to use the default voice.

The tool returns an "id" in the format @@GENERATED@@<uuid>@@. Always include this exact ID in the final message, with or without a Markdown link. Never invent a generated file ID.
EOT;

        parent::__construct('generate_speech', $description);
    }

    public function __invoke(
        string $text,
        ?string $voice = null,
        ?string $filename = null,
    ): string {
        try {
            $fileId = $this->audioGeneratorService->generate(
                $this->session,
                $this->threadId,
                $text,
                $voice,
                $filename,
            );

            return json_encode([
                'status' => 'success',
                'message' => 'Speech generated successfully',
                'id' => $fileId,
                'name' => ($filename ?? 'synthese-vocale') . '.mp3',
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            return json_encode([
                'status' => 'error',
                'message' => 'Error generating speech: ' . $throwable->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    #[\Override]
    public function postProcessMessage(Message $message): Message
    {
        $fileId = $this->extractFileId();
        if ($fileId === null || str_contains($message->getContent() ?? '', $fileId)) {
            return $message;
        }

        $newContent = ($message->getContent() ?? '') . "\n" . $fileId;
        $blocks = [];
        $hasTextBlock = false;
        foreach ($message->getContentBlocks() as $contentBlock) {
            if ($contentBlock instanceof TextContent && ! $hasTextBlock) {
                $blocks[] = new TextContent($newContent);
                $hasTextBlock = true;
            } else {
                $blocks[] = $contentBlock;
            }
        }

        if (! $hasTextBlock) {
            $blocks[] = new TextContent($fileId);
        }

        $message->setContents($blocks);

        return $message;
    }

    /** @return array<int, ToolProperty> */
    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'text',
                PropertyType::STRING,
                'The text to synthesize, limited to 4096 characters.',
                true,
            ),
            new ToolProperty(
                'voice',
                PropertyType::STRING,
                'Configured voice identifier. Omit it to use the default voice.',
                false,
            ),
            new ToolProperty(
                'filename',
                PropertyType::STRING,
                'Human-readable filename without the .mp3 extension.',
                false,
            ),
        ];
    }

    private function extractFileId(): ?string
    {
        $result = $this->getResult();
        if ($result === null) {
            return null;
        }

        try {
            $data = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data)
            && ($data['status'] ?? null) === 'success'
            && is_string($data['id'] ?? null)
                ? $data['id']
                : null;
    }
}
