<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Session\SessionInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class ComfyUIService
{
    public const string IMAGE_PATTERN = '/@@GENERATED@@([a-zA-Z0-9_\-@]+\.(?:png|jpg|jpeg|gif|webp))@@/i';

    public const string FOLDER_PREFIX = 'generated';

    public const string FOLDER_SEPARATOR = '@';

    private Client $httpClient;

    public function __construct(
        private Settings $settings,
        private Filesystem $filesystem,
    ) {
        $this->httpClient = new Client([
            'base_uri' => $this->settings->get('comfyui.url'),
            'timeout' => $this->settings->get('comfyui.timeout') ?? 300.0,
        ]);
    }

    public function generateImage(SessionInterface $session, string $prompt): string
    {
        try {
            $workflow = $this->getWorkflow($prompt);
            $promptId = $this->queuePrompt($workflow);
            $imageData = $this->waitForResult($promptId);

            return $this->saveImage($session, $imageData);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'ComfyUI API error: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        } catch (FilesystemException $e) {
            throw new RuntimeException(
                'Failed to save generated image: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the workflow JSON with the prompt placeholder replaced.
     *
     * @return array<string, mixed> The workflow configuration
     */
    private function getWorkflow(string $prompt): array
    {
        $workflow = $this->settings->get('comfyui.workflow');
        $workflow = str_replace('{{PROMPT}}', addslashes($prompt), $workflow);

        return json_decode($workflow, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Queue a prompt for execution.
     *
     * @param array<string, mixed> $workflow
     *
     * @throws GuzzleException
     *
     * @return string The prompt ID
     */
    private function queuePrompt(array $workflow): string
    {
        $payload = [
            'prompt' => $workflow,
            'client_id' => uniqid('claire_', true),
        ];

        $response = $this->httpClient->post('/prompt', [
            'json' => $payload,
        ]);

        $result = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (! isset($result['prompt_id'])) {
            throw new RuntimeException('Failed to queue prompt: no prompt_id received');
        }

        return (string) $result['prompt_id'];
    }

    /**
     * Wait for the generation to complete and retrieve image info.
     *
     * @throws GuzzleException
     * @throws RuntimeException
     *
     * @return array{filename: string, subfolder: string, type: string}
     */
    private function waitForResult(string $promptId): array
    {
        $timeout = (int) ($this->settings->get('comfyui.timeout') ?? 300);
        $startTime = time();
        $pollInterval = 2;

        while (time() - $startTime < $timeout) {
            $response = $this->httpClient->get('/history/' . $promptId);
            $history = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (! isset($history[$promptId])) {
                sleep($pollInterval);
                continue;
            }

            $promptData = $history[$promptId];

            // Check for errors
            if (isset($promptData['status']['status_str']) && $promptData['status']['status_str'] === 'error') {
                throw new RuntimeException('ComfyUI workflow execution failed');
            }

            // Check if outputs are ready
            if (empty($promptData['outputs'])) {
                sleep($pollInterval);
                continue;
            }

            // Extract image data from the first output node with images
            foreach ($promptData['outputs'] as $output) {
                if (isset($output['images']) && $output['images'] !== []) {
                    $image = $output['images'][0];

                    return [
                        'filename' => (string) ($image['filename'] ?? ''),
                        'subfolder' => (string) ($image['subfolder'] ?? ''),
                        'type' => (string) ($image['type'] ?? 'output'),
                    ];
                }
            }

            sleep($pollInterval);
        }

        throw new RuntimeException('ComfyUI generation timed out after ' . $timeout . ' seconds');
    }

    /**
     * Download the generated image from ComfyUI.
     *
     * @param array{filename: string, subfolder: string, type: string} $imageData
     *
     * @throws GuzzleException
     *
     * @return string The image binary data
     */
    private function downloadImage(array $imageData): string
    {
        $params = [
            'filename' => $imageData['filename'],
            'subfolder' => $imageData['subfolder'],
            'type' => $imageData['type'],
        ];

        $response = $this->httpClient->get('/view', [
            'query' => $params,
        ]);

        return (string) $response->getBody();
    }

    /**
     * Save the image to the filesystem.
     *
     * @throws FilesystemException
     *
     * @return string The local file path
     */
    private function saveImage(SessionInterface $session, array $imageData): string
    {
        $imageContent = $this->downloadImage($imageData);
        $extension = pathinfo((string) $imageData['filename'], PATHINFO_EXTENSION) ?: 'png';
        $filename = Uuid::uuid4() . '.' . $extension;
        $localPath = self::FOLDER_PREFIX . '/' . $session->get(Auth::USERID) . '/' . $filename;
        $imgId = '@@GENERATED@@' . $session->get(Auth::USERID) . self::FOLDER_SEPARATOR . $filename . '@@';

        $this->filesystem->write($localPath, $imageContent);

        return $imgId;
    }
}
