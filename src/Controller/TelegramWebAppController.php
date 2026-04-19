<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Settings;
use App\Services\TelegramService;
use App\Services\TelegramWebAppValidator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;

final readonly class TelegramWebAppController
{
    public function __construct(
        private Twig $twig,
        private TelegramWebAppValidator $telegramWebAppValidator,
        private Settings $settings,
        private BrainRegistry $brainRegistry,
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private EntityManagerInterface $entityManager,
        private Logger $logger,
        private TelegramService $telegramService,
    ) {
    }

    /**
     * Render the main WebApp page.
     *
     * The initData is retrieved client-side via Telegram.WebApp.initData
     * and validated by the API endpoints.
     */
    public function index(Request $request, Response $response): Response
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
            return $this->jsonResponse($response, ['error' => 'Invalid request body'], 400);
        }

        $initData = $body['initData'] ?? '';

        if (! $this->validateInitData($initData)) {
            return $this->jsonResponse($response, ['error' => 'Invalid authentication'], 401);
        }

        $userId = $this->telegramWebAppValidator->extractUserId($initData);
        if ($userId === null) {
            return $this->jsonResponse($response, ['error' => 'User not found'], 401);
        }

        if (! $this->userExists($userId)) {
            return $this->jsonResponse($response, ['error' => 'User not authorized'], 403);
        }

        try {
            // Check if this is an update request
            $isUpdate = isset($body['brain_avatar']) || isset($body['comfyui_workflow']);
            $isNewChat = isset($body['action']) && $body['action'] === 'new_chat';

            if ($isNewChat) {
                // In private chats, chatId equals userId
                $telegramChatId = (int) $userId;
                $success = $this->telegramService->startNewChat($userId, $telegramChatId);

                return $this->jsonResponse($response, ['success' => $success]);
            }

            if ($isUpdate) {
                // Update brain_avatar if provided
                if (isset($body['brain_avatar'])) {
                    $success = $this->telegramService->updateUserSetting(
                        $userId,
                        'brain_avatar',
                        $body['brain_avatar']
                    );
                    if (! $success) {
                        return $this->jsonResponse(
                            $response,
                            ['error' => 'Invalid brain avatar'],
                            400,
                        );
                    }
                }

                // Update comfyui_workflow if provided
                if (isset($body['comfyui_workflow']) && $this->comfyUIWorkflowRegistry->isEnabled()) {
                    $success = $this->telegramService->updateUserSetting(
                        $userId,
                        'comfyui_workflow',
                        $body['comfyui_workflow']
                    );
                    if (! $success) {
                        return $this->jsonResponse(
                            $response,
                            ['error' => 'Invalid workflow'],
                            400,
                        );
                    }
                }

                return $this->jsonResponse($response, ['success' => true]);
            }

            // Get settings
            $settings = $this->telegramService->getUserSettings($userId);
            if ($settings === null) {
                return $this->jsonResponse($response, ['error' => 'Failed to load settings'], 500);
            }

            return $this->jsonResponse($response, ['success' => true, 'settings' => $settings]);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to process API request: ' . $throwable->getMessage());

            return $this->jsonResponse($response, ['error' => 'Failed to process request'], 500);
        }
    }

    /**
     * Validate initData using the bot token.
     */
    private function validateInitData(string $initData): bool
    {
        $botToken = $this->settings->get('telegram.bot_token');
        if (! is_string($botToken) || $botToken === '') {
            $this->logger->error('Telegram bot token not configured');

            return false;
        }

        return $this->telegramWebAppValidator->validateInitData($initData, $botToken);
    }

    /**
     * Check if a user with the given telegram_id exists.
     */
    private function userExists(string $telegramId): bool
    {
        $entityRepository = $this->entityManager->getRepository(User::class);

        if (! $entityRepository instanceof UserRepository) {
            throw new InvalidArgumentException('Expected UserRepository but got ' . $entityRepository::class);
        }

        return $entityRepository->findByTelegramId($telegramId) instanceof User;
    }

    /**
     * Write JSON response.
     *
     * @param array<string, mixed> $data
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
