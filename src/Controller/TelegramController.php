<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Job\Telegram\StartThreadJob;
use App\Renderer\JsonRenderer;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Settings;
use App\Services\TelegramService;
use App\Services\TelegramValidator;
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
    ) {
    }

    public function webhook(Request $request, Response $response): Response
    {
        try {
            $this->telegramValidator->validateSecretToken($request);
            $rawBody = $request->getBody()->getContents();
            Update::fromJson($rawBody);
        } catch (InvalidArgumentException) {
            return $response->withStatus(401);
        }

        try {
            $this->queueDispatcher->dispatch(
                TelegramService::class,
                ['update_json' => $rawBody],
                $this->settings['queue.defaultQueue']
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram Webhook Error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
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
            'brains' => $brains,
            'workflows' => $workflows,
            'comfyui_enabled' => $comfyUIEnabled,
            'base_url' => $baseUrl,
        ]);
    }

    /**
     * API endpoint to get/update user settings.
     * If brain_avatar or comfyui_workflow provided -> update
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
            $this->telegramService->manageSession($telegramUserId);

            // Check if this is an update request
            $isUpdate = isset($body['brain_avatar']) || isset($body['comfyui_workflow']);
            $isNewChat = isset($body['action']) && $body['action'] === 'new_chat';

            if ($isNewChat) {
                // In private chats, chatId equals userId
                $this->queueDispatcher->dispatch(
                    StartThreadJob::class,
                    [
                        'telegramUserId' => $telegramUserId,
                    ],
                    $this->settings->get('queue.defaultQueue')
                );
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

                return $this->jsonRenderer->json($response, ['success' => true]);
            }

            // Get settings
            $settings = $this->telegramService->getUserSettings($telegramUserId);
            if ($settings === null) {
                return $this->jsonRenderer->json($response, ['success' => false, 'error' => 'Failed to load settings'], 500);
            }

            return $this->jsonRenderer->json($response, ['success' => true, 'settings' => $settings]);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to process API request: ' . $throwable->getMessage());

            return $this->jsonRenderer->json($response, ['success' => false,'error' => 'Failed to process request'], 200);
        }
    }
}
