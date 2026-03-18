<?php

declare(strict_types=1);

namespace App\Brain\Tools;

use App\Services\ComfyUIService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GenerateImageTool extends Tool
{
    public function __construct(
        private readonly ComfyUIService $comfyUIService,
        private readonly Settings $settings,
        private readonly SessionInterface $session,
    ) {
        parent::__construct(
            'generate_image',
            <<<EOT
Generates an image from a text description using ComfyUI, generated image will be send to the user.
Use this tool when the user asks you to create, generate, draw or send photo/image/pictures. 
Provide a detailed prompt describing the desired image content, style, and composition.
EOT
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

            // Note: negative_prompt is currently not used as ComfyUIService
            // uses the workflow's embedded negative prompt. This parameter
            // is reserved for future enhancement.
            $localPath = $this->comfyUIService->generateImage($this->session, $prompt);

            return 'Image generated successfully path: ' . $localPath;
        } catch (\Exception $exception) {
            return 'Error generating image: ' . $exception->getMessage();
        }
    }

    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'prompt',
                PropertyType::STRING,
                'The text description of the image to generate. Be detailed and descriptive.',
                true
            ),
        ];
    }
}
