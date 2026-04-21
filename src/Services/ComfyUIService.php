<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\ChatHistoryFile;
use App\Repository\ChatHistoryRepository;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
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
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private EntityManagerInterface $entityManager,
        private ChatHistoryRepository $chatHistoryRepository,
    ) {
        $this->httpClient = new Client([
            'base_uri' => $this->settings->get('comfyui.url'),
            'timeout' => $this->settings->get('comfyui.timeout') ?? 300.0,
        ]);
    }

    public function generateImage(SessionInterface $session, string $prompt): string
    {
        try {
            $workflow = $this->getWorkflow($session, $prompt);
            $promptId = $this->queuePrompt($workflow);
            $imageData = $this->waitForResult($promptId);

            return $this->saveImage($session, $imageData, $prompt);
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
    private function getWorkflow(SessionInterface $session, string $prompt): array
    {
        $workflow = $this->resolveWorkflow($session);
        $workflow = str_replace(
            [
                '{{PROMPT}}',
                '{{SEED}}',
            ],
            [
                addcslashes($prompt, '"'),
                (string) random_int(1, PHP_INT_MAX),
            ],
            $workflow
        );

        return json_decode($workflow, true, 512, JSON_THROW_ON_ERROR);
    }

    private function resolveWorkflow(SessionInterface $session): string
    {
        $workflowSlug = (string) $session->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');

        if ($workflowSlug !== '' && $this->comfyUIWorkflowRegistry->has($workflowSlug)) {
            return $this->comfyUIWorkflowRegistry->getWorkflow($workflowSlug);
        }

        $selectedWorkflow = $this->comfyUIWorkflowRegistry->getDefaultSlug();
        if ($selectedWorkflow !== null && $this->comfyUIWorkflowRegistry->has($selectedWorkflow)) {
            return $this->comfyUIWorkflowRegistry->getWorkflow($selectedWorkflow);
        }

        throw new RuntimeException('Aucun workflow ComfyUI disponible');
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
            $result = $this->pollForResult($promptId);

            if ($result === null) {
                sleep($pollInterval);
                continue;
            }

            return $result;
        }

        throw new RuntimeException('ComfyUI generation timed out after ' . $timeout . ' seconds');
    }

    /**
     * @return array{filename: string, subfolder: string, type: string}|null
     *
     * @throws GuzzleException
     */
    private function pollForResult(string $promptId): ?array
    {
        $promptData = $this->fetchPromptData($promptId);

        if ($promptData === null) {
            return null;
        }

        if ($this->isPromptError($promptData)) {
            throw new RuntimeException('ComfyUI workflow execution failed');
        }

        if (empty($promptData['outputs'])) {
            return null;
        }

        return $this->extractImageFromOutputs($promptData['outputs']);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws GuzzleException
     */
    private function fetchPromptData(string $promptId): ?array
    {
        $response = $this->httpClient->get('/history/' . $promptId);
        $history = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $history[$promptId] ?? null;
    }

    /**
     * @param array<string, mixed> $promptData
     */
    private function isPromptError(array $promptData): bool
    {
        return isset($promptData['status']['status_str'])
            && $promptData['status']['status_str'] === 'error';
    }

    /**
     * @param array<string, mixed> $outputs
     *
     * @return array{filename: string, subfolder: string, type: string}|null
     */
    private function extractImageFromOutputs(array $outputs): ?array
    {
        foreach ($outputs as $output) {
            if (isset($output['images']) && $output['images'] !== []) {
                $image = $output['images'][0];

                return [
                    'filename' => (string) ($image['filename'] ?? ''),
                    'subfolder' => (string) ($image['subfolder'] ?? ''),
                    'type' => (string) ($image['type'] ?? 'output'),
                ];
            }
        }

        return null;
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
     * @param array{filename: string, subfolder: string, type: string} $imageData
     *
     * @throws FilesystemException
     *
     * @return string The local file path
     */
    private function saveImage(SessionInterface $session, array $imageData, string $prompt): string
    {
        $imageContent = $this->downloadImage($imageData);
        $detectedExtension = pathinfo(
            (string) $imageData['filename'],
            PATHINFO_EXTENSION
        );
        $extension = is_string($detectedExtension) && $detectedExtension !== ''
            ? $detectedExtension
            : 'png';
        $filename = Uuid::uuid4() . '.' . $extension;
        $userId = $session->get(Auth::USERID);
        $localPath = self::FOLDER_PREFIX . '/' . $userId . '/' . $filename;
        $imgId = '@@GENERATED@@' . $userId . self::FOLDER_SEPARATOR . $filename . '@@';

        $this->filesystem->write($localPath, $imageContent);
        $this->saveFileReference($session, $localPath, $prompt);

        return $imgId;
    }

    /**
     * Save file reference to database.
     */
    private function saveFileReference(SessionInterface $session, string $filePath, string $prompt): void
    {
        $threadId = $session->get('threadId');

        if ($threadId === null) {
            return;
        }

        $history = $this->chatHistoryRepository->findOneBy(['threadId' => $threadId]);

        if ($history === null) {
            return;
        }

        $workflowSlug = (string) $session->get(ComfyUIWorkflowRegistry::SESSION_KEY, '');

        if ($workflowSlug === '' || ! $this->comfyUIWorkflowRegistry->has($workflowSlug)) {
            $workflowSlug = $this->comfyUIWorkflowRegistry->getDefaultSlug() ?? '';
        }

        $chatHistoryFile = new ChatHistoryFile();
        $chatHistoryFile->setHistory($history);
        $chatHistoryFile->setUser($history->getUser());
        $chatHistoryFile->setFileType('image');
        $chatHistoryFile->setFilePath($filePath);
        $chatHistoryFile->setMetadata([
            'prompt' => $prompt,
            'workflow' => $workflowSlug,
        ]);

        $this->entityManager->persist($chatHistoryFile);
        $this->entityManager->flush();
    }
}
