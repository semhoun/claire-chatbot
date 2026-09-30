<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\LongTermMemory;
use App\Job\Telegram\StartThreadJob;
use App\Renderer\JsonRenderer;
use App\Services\Audio\AudioServiceInterface;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\SemanticMemoryRegistry;
use App\Services\Settings;
use App\Services\TelegramService;
use App\Services\TelegramStopIngress;
use App\Services\TelegramValidator;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Phptg\BotApi\Type\Update\Update;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;

final readonly class TelegramController
{
    public function __construct(
        private Logger $logger,
        private Twig $twig,
        private JsonRenderer $jsonRenderer,
        private TelegramValidator $telegramValidator,
        private BrainRegistry $brainRegistry,
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private TelegramService $telegramService,
        private QueueDispatcherInterface $queueDispatcher,
        private Settings $settings,
        private AudioServiceInterface $audioService,
        private TelegramStopIngress $telegramStopIngress,
        private Connection $connection,
        private SemanticMemoryRegistry $semanticMemory,
    ) {
    }

    public function webhook(Request $request, Response $response): Response
    {
        try {
            $this->telegramValidator->validateSecretToken($request);
            $rawBody = $request->getBody()->getContents();
            $update = Update::fromJson($rawBody);
        } catch (InvalidArgumentException) {
            return $response->withStatus(401);
        }

        try {
            if ($this->settings->get('llm.stop.enabled', false)
                && (string) $this->settings->get('telegram.webhook_secret', '') === '') {
                return $response->withStatus(401);
            }
            if ($this->settings->get('llm.stop.enabled', false) && $this->telegramStopIngress->handle($update)) {
                return $response->withStatus(204);
            }
            $hasAudio = $update->message?->voice !== null || $update->message?->audio !== null;
            if ($this->settings->get('llm.stop.enabled', false)
                && (! $hasAudio || $this->audioService->isAvailable())) {
                $this->telegramStopIngress->accept($update);
            }
            $this->queueDispatcher->dispatch(
                TelegramService::class,
                ['update_json' => $rawBody],
                $this->settings->get('queue.defaultQueue')
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram Webhook Error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
            return $response->withStatus(503)->withHeader('Retry-After', '5');
        }

        return $response->withStatus(204);
    }

    /**
     * Render the main WebApp page.
     *
     * The initData is retrieved client-side via Telegram.WebApp.initData
     * and validated by the API endpoints.
     */
    public function webAppIndex(Request $request, Response $response): Response
    {
        // Get base URL from request attribute (set by BaseUrlMiddleware)
        $baseUrl = $request->getAttribute('base_url');

        // Prepare data for the template
        $brains = $this->brainRegistry->list();
        $workflows = $this->comfyUIWorkflowRegistry->list();
        $comfyUIEnabled = $this->comfyUIWorkflowRegistry->isEnabled();

        return $this->twig->render($response, 'telegram/webapp.twig', [
            'base_url' => (string) $baseUrl,
            'brains' => $brains,
            'workflows' => $workflows,
            'comfyui_enabled' => $comfyUIEnabled,
            'audio_available' => $this->audioService->isAvailable(),
            'audio_voices' => $this->audioService->voices(),
            'semantic_memory_available' => (bool) $this->settings->get('llm.semanticMemory.enabled', false),
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * API endpoint to get/update user settings.
     * If a supported setting is provided, update it.
     * Otherwise -> get current settings.
     */
    public function api(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();

        if (! is_array($body)) {
            return $this->jsonRenderer->json($response, ['error' => 'Invalid request body'], 400);
        }

        $telegramUserId = $this->telegramValidator->appGetTelegramUserId($body['initData'] ?? '');
        if ($telegramUserId === null) {
            return $this->jsonRenderer->json($response, ['error' => 'User not authorized'], 401);
        }

        try {
            $semanticAvailable = (bool) $this->settings->get('llm.semanticMemory.enabled', false);
            $semanticAction = array_key_exists('semantic_memory_enabled', $body)
                || in_array($body['action'] ?? '', ['semantic_memory', 'erase_semantic_memory'], true);
            if ($semanticAction) {
                if (! $semanticAvailable) {
                    return $this->jsonRenderer->json($response, ['error' => 'Semantic memory unavailable'], 404);
                }
                $userId = $this->connection->fetchOne(
                    'SELECT id FROM account WHERE telegram_id = ?',
                    [(string) $telegramUserId]
                );
                if ($userId === false) {
                    return $this->jsonRenderer->json($response, ['error' => 'User not authorized'], 403);
                }
                if (array_key_exists('semantic_memory_enabled', $body)) {
                    if (! is_bool($body['semantic_memory_enabled'])) {
                        return $this->jsonRenderer->json($response, ['error' => 'Expected boolean preference'], 400);
                    }
                    $this->semanticMemory->setEnabled($userId, $body['semantic_memory_enabled']);
                } elseif (($body['action'] ?? '') === 'erase_semantic_memory') {
                    $this->semanticMemory->erase($userId);
                }
                return $this->jsonRenderer->json($response, [
                    'success' => true, 'semanticMemory' => $this->semanticMemory->preference($userId),
                ]);
            }
            $this->telegramService->manageSession($telegramUserId);

            // Check if this is an update request
            $isUpdate = isset($body['brain_avatar'])
                || isset($body['comfyui_workflow'])
                || isset($body[AudioServiceInterface::VOICE_SESSION_KEY])
                || isset($body[LongTermMemory::SESSION_KEY]);
            $isNewChat = isset($body['action']) && $body['action'] === 'new_chat';
            $isMemoryRebuild = isset($body['action'])
                && $body['action'] === 'rebuild_long_term_memory';

            if ($isMemoryRebuild) {
                $this->telegramService->rebuildLongTermMemory();

                return $this->jsonRenderer->json($response, ['success' => true]);
            }

            if ($isNewChat) {
                // In private chats, chatId equals userId
                try {
                    $this->queueDispatcher->dispatch(
                        StartThreadJob::class,
                        ['telegramUserId' => $telegramUserId],
                        $this->settings->get('queue.defaultQueue')
                    );
                } catch (\Throwable $throwable) {
                    $this->logger->error('Failed to enqueue Telegram new chat', ['exception' => $throwable]);
                    return $this->jsonRenderer->json(
                        $response->withHeader('Retry-After', '5'),
                        ['success' => false, 'error' => 'Queue unavailable'],
                        503,
                    );
                }

                return $this->jsonRenderer->json($response, ['success' => true]);
            }

            if ($isUpdate) {
                // Update brain_avatar if provided
                if (isset($body['brain_avatar'])) {
                    $success = $this->telegramService->updateUserSetting(
                        'brain_avatar',
                        $body['brain_avatar']
                    );
                    if (! $success) {
                        return $this->jsonRenderer->json(
                            $response,
                            ['error' => 'Invalid brain avatar'],
                            400,
                        );
                    }
                }

                // Update comfyui_workflow if provided
                if (isset($body['comfyui_workflow']) && $this->comfyUIWorkflowRegistry->isEnabled()) {
                    $success = $this->telegramService->updateUserSetting(
                        'comfyui_workflow',
                        $body['comfyui_workflow']
                    );
                    if (! $success) {
                        return $this->jsonRenderer->json(
                            $response,
                            ['error' => 'Invalid workflow'],
                            400,
                        );
                    }
                }

                if (isset($body[LongTermMemory::SESSION_KEY])) {
                    $success = $this->telegramService->updateUserSetting(
                        LongTermMemory::SESSION_KEY,
                        $body[LongTermMemory::SESSION_KEY]
                    );
                    if (! $success) {
                        return $this->jsonRenderer->json(
                            $response,
                            ['error' => 'Invalid long-term memory setting'],
                            400,
                        );
                    }
                }

                if (isset($body[AudioServiceInterface::VOICE_SESSION_KEY])) {
                    $success = $this->telegramService->updateUserSetting(
                        AudioServiceInterface::VOICE_SESSION_KEY,
                        $body[AudioServiceInterface::VOICE_SESSION_KEY],
                    );
                    if (! $success) {
                        return $this->jsonRenderer->json(
                            $response,
                            ['error' => 'Invalid audio voice'],
                            400,
                        );
                    }
                }

                return $this->jsonRenderer->json($response, ['success' => true]);
            }

            // Get settings
            $settings = $this->telegramService->getUserSettings($telegramUserId);
            if ($settings === null) {
                return $this->jsonRenderer->json($response, ['success' => false, 'error' => 'Failed to load settings'], 500);
            }

            $settings['semantic_memory_enabled'] = false;
            if ($semanticAvailable) {
                $userId = $this->connection->fetchOne(
                    'SELECT id FROM account WHERE telegram_id = ?',
                    [(string) $telegramUserId]
                );
                if ($userId !== false) {
                    $settings['semantic_memory_enabled'] = $this->semanticMemory->preference($userId)['enabled'];
                }
            }
            return $this->jsonRenderer->json($response, ['success' => true, 'settings' => $settings]);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to process API request: ' . $throwable->getMessage());

            return $this->jsonRenderer->json($response, ['success' => false,'error' => 'Failed to process request'], 200);
        }
    }
}
