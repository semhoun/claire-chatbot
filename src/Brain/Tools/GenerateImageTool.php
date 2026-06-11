<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Services\ComfyUIService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use JsonException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Psr\Log\LoggerInterface;

class GenerateImageTool extends Tool implements MessagePostProcessorInterface
{
    public function __construct(
        private readonly ComfyUIService $comfyUIService,
        private readonly Settings $settings,
        private readonly SessionInterface $session,
        private readonly string $threadId,
        private readonly LoggerInterface $logger,
    ) {
        $description = <<<EOT
Generates an image from a text description. The generated image will be sent to the user.
IMPORTANT: Use this tool whenever the user requests or needs an image, photo, drawing, illustration, or any other visual output.
The prompt must be written in natural English, using complete sentences and enough visual detail to clearly describe the scene. Do not use specific character names; use only generic character types such as rabbit, man, woman, child, thief, or robot.
To insert a generated image into the text, provide only its ID (for example: @@GENERATED@@<uuid>@@) with or without use of <img> tag.
IMPORTANT: Use ONLY image IDs that have been explicitly provided by the generate_image tool in the current conversation. NEVER invent, placeholder, or hallucinate image IDs (like @@GENERATED@@placeholder@@). If you haven't called the tool yet, you don't have an ID to use.
EOT;

        parent::__construct(
            'generate_image',
            $description
        );
    }

    public function __invoke(
        string $prompt
    ): string {
        try {
            $enabled = $this->settings->get('tools.comfyui.enabled');

            if (! $enabled) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Image generation is not enabled.',
                ], JSON_THROW_ON_ERROR);
            }

            $imgId = $this->comfyUIService->generateImage($this->session, $this->threadId, $prompt);

            return json_encode([
                'status' => 'success',
                'message' => 'Image generated successfully',
                'id' => $imgId,
            ], JSON_THROW_ON_ERROR);
        } catch (\Exception $exception) {
            $this->logger->error('Image generation failed', [
                'exception' => $exception,
                'prompt' => $prompt,
                'threadId' => $this->threadId,
            ]);
            return json_encode([
                'status' => 'error',
                'message' => 'Error generating image: ' . $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Post-process the assistant's message to ensure the image ID is included.
     *
     * This method checks if the tool result contains a generated image ID,
     * and if so, ensures it's present in the assistant's final message.
     * If the ID is missing, it appends it to the message content.
     */
    #[\Override]
    public function postProcessMessage(Message $message): Message
    {
        $imageId = $this->extractImageId();

        if ($imageId === null) {
            return $message;
        }

        if ($this->isImageIdInMessage($message, $imageId)) {
            return $message;
        }

        return $this->appendImageIdToMessage($message, $imageId);
    }

    #[\Override]
    /** @return array<int, ToolProperty> */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'prompt',
                PropertyType::STRING,
                'The text description of the image to generate. Use natural english language with complete sentences.',
                true
            ),
        ];
    }

    private function extractImageId(): ?string
    {
        $result = $this->getResult();

        if ($result === null) {
            return null;
        }

        try {
            $resultData = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

            if (! isset($resultData['status'], $resultData['id']) || $resultData['status'] !== 'success') {
                return null;
            }

            return $resultData['id'];
        } catch (JsonException) {
            return null;
        }
    }

    private function isImageIdInMessage(Message $message, string $imageId): bool
    {
        $messageContent = $message->getContent();
        if ($messageContent === null) {
            return true;
        }

        return str_contains($messageContent, $imageId);
    }

    private function appendImageIdToMessage(Message $message, string $imageId): Message
    {
        $newContent = $message->getContent() . "\n" . $imageId;
        $updatedBlocks = $this->updateContentBlocks($message->getContentBlocks(), $newContent, $imageId);

        $message->setContents($updatedBlocks);

        return $message;
    }

    /**
     * @param array<int, mixed> $contentBlocks
     *
     * @return array<int, mixed>
     */
    private function updateContentBlocks(array $contentBlocks, string $newContent, string $imageId): array
    {
        $updatedBlocks = [];
        $hasTextBlock = false;

        foreach ($contentBlocks as $contentBlock) {
            if ($contentBlock instanceof TextContent && ! $hasTextBlock) {
                $updatedBlocks[] = new TextContent($newContent);
                $hasTextBlock = true;
            } else {
                $updatedBlocks[] = $contentBlock;
            }
        }

        if (! $hasTextBlock) {
            $updatedBlocks[] = new TextContent($imageId);
        }

        return $updatedBlocks;
    }
}
