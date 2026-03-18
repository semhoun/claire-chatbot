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
        $promptStyle = $this->settings->get('comfyui.prompt_style');
        $description = $promptStyle === 'flux'
            ? <<<EOT
Generates an image from a text description using ComfyUI, generated image will be send to the user.
IMPORTANT: This tool MUST be used whenever the user needs an image or photo - whether they ask to create, generate, draw, or simply want/need an image.
Any request that requires providing an image, picture, or photo should use this tool.
The prompt should be written in natural language (Flux style), describing the scene in detail with complete sentences.
EOT
            : <<<EOT
Generates an image from a text description using ComfyUI, generated image will be send to the user.
IMPORTANT: This tool MUST be used whenever the user needs an image or photo - whether they ask to create, generate, draw, or simply want/need an image.
Any request that requires providing an image, picture, or photo should use this tool.
The prompt should be formatted as comma-separated keywords (SDXL style), for example: "masterpiece, best quality, sunlit forest, vibrant colors, detailed trees, cinematic lighting".
Avoid natural language sentences; use descriptive tags and keywords for best results.
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
            ]);
        }
    }

    #[\Override]
    protected function properties(): array
    {
        $promptStyle = $this->settings->get('comfyui.prompt_style');
        $propertyDescription = $promptStyle === 'flux'
            ? 'The text description of the image to generate. Use natural language with complete sentences (Flux style).'
            : 'The text description of the image to generate. Use comma-separated keywords (SDXL style). Be detailed and descriptive.';

        return [
            new ToolProperty(
                'prompt',
                PropertyType::STRING,
                $propertyDescription,
                true
            ),
        ];
    }
}
