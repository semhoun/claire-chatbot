<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Services\ComfyUIService;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use JsonException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GenerateImageTool extends Tool implements MessagePostProcessorInterface
{
    public function __construct(
        private readonly ComfyUIService $comfyUIService,
        private readonly Settings $settings,
        private readonly SessionInterface $session,
        private readonly ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
    ) {
        $promptStyle = $this->getPromptStyle();
        $description = $promptStyle === 'flux'
            ? <<<EOT
Generates an image from a text description using ComfyUI. The generated image will be sent to the user.
IMPORTANT: Use this tool whenever the user requests or needs an image, photo, drawing, illustration, or any other visual output.
The prompt must be written in natural English, using complete sentences and enough visual detail to clearly describe the scene. Do not use specific character names; use only generic character types such as rabbit, man, woman, child, thief, or robot.
To insert a generated image into the text, provide only its ID (for example: @@GENERATED@@<dae5bb85-1b5d-4311-9d88-e512d1aad88b@81fb5e49-5c65-4e28-affe-bd42cf2b4a8d.png>@@) and do not use an <img> tag.
EOT
            : <<<EOT
Generates an image from a text description using ComfyUI. The generated image will be sent to the user.
IMPORTANT: This tool must be used whenever the user requests or needs an image, photo, drawing, illustration, or any other visual output, including any request to create, generate, or draw an image.
The prompt must be written as comma-separated English keywords, for example: masterpiece, best quality, sunlit forest, vibrant colors, detailed trees, cinematic lighting. Use descriptive tags and keywords rather than natural language sentences for best results.
When referring to characters, do not use specific names; use only generic character types such as rabbit, man, woman, child, thief, or robot.
To insert a generated image into the text, provide only its ID (for example: @@GENERATED@@<dae5bb85-1b5d-4311-9d88-e512d1aad88b@81fb5e49-5c65-4e28-affe-bd42cf2b4a8d.png>@@) and do not use an <img> tag.
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
            $enabled = $this->settings->get('comfyui.enabled');

            if (! $enabled) {
                return 'Error: Image generation is not enabled. ComfyUI is disabled in the configuration.';
            }

            $imgId = $this->comfyUIService->generateImage($this->session, $prompt);

            return json_encode([
                'status' => 'success',
                'message' => 'Image generated successfully',
                'id' => $imgId,
            ], JSON_THROW_ON_ERROR);
        } catch (\Exception $exception) {
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
        $result = $this->getResult();

        if ($result === null) {
            return $message;
        }

        try {
            $resultData = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

            // Check if the result contains a successfully generated image
            if (! isset($resultData['status'], $resultData['id']) || $resultData['status'] !== 'success') {
                return $message;
            }

            $imageId = $resultData['id'];
            $messageContent = $message->getContent() ?? '';

            // If the image ID is already in the message, no need to modify
            if (str_contains($messageContent, (string) $imageId)) {
                return $message;
            }

            // Append the image ID to the message content
            $newContent = $messageContent . "\n" . $imageId;

            // Replace the text content blocks with the updated content
            $contentBlocks = $message->getContentBlocks();
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

            // If no text block exists, add one
            if (! $hasTextBlock) {
                $updatedBlocks[] = new TextContent($imageId);
            }

            $message->setContents($updatedBlocks);

            return $message;
        } catch (JsonException) {
            // If result is not valid JSON, return message unchanged
            return $message;
        }
    }

    #[\Override]
    protected function properties(): array
    {
        $promptStyle = $this->getPromptStyle();
        $propertyDescription = $promptStyle === 'flux'
            ? 'The text description of the image to generate. Use natural english language with complete sentences.'
            : 'The text description of the image to generate. Use comma-separated english keywords. Be detailed and descriptive.';

        return [
            new ToolProperty(
                'prompt',
                PropertyType::STRING,
                $propertyDescription,
                true
            ),
        ];
    }

    private function getPromptStyle(): string
    {
        $workflow = (string) $this->session->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');
        if ($workflow !== '' && $this->comfyUIWorkflowRegistry->has($workflow)) {
            return $this->comfyUIWorkflowRegistry->getMeta($workflow)['type'];
        }

        $defaultWorkflow = $this->comfyUIWorkflowRegistry->getDefaultSlug();
        if ($defaultWorkflow !== null) {
            return $this->comfyUIWorkflowRegistry->getMeta($defaultWorkflow)['type'];
        }

        return 'sdxl';
    }
}
