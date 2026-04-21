<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;

final readonly class TelegramValidator
{
    public function __construct(
        private Logger $logger,
        private EntityManagerInterface $entityManager,
        private Settings $settings,
    )
    {
    }

    /**
     * Validate Telegram WebApp initData HMAC signature.
     *
     * The initData is validated using HMAC-SHA256 with the bot token.
     * The key is computed as: HMAC_SHA256(bot_token, "WebAppData")
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    private function validateAppInitData(string $initData): bool
    {
        if ($initData === '') {
            $this->logger->warning('Empty initData or botToken');
            return false;
        }

        $botToken = $this->settings->get('telegram.bot_token');
        if ($botToken === '') {
            $this->logger->error('Empty botToken');
            return false;
        }

        // Parse initData query string
        $data = [];
        parse_str($initData, $data);

        // Extract hash
        $hash = $data['hash'] ?? '';
        if ($hash === '') {
            return false;
        }

        unset($data['hash']);

        // Sort keys alphabetically and build data-check-string
        ksort($data);
        $dataCheckString = '';
        foreach ($data as $key => $value) {
            $dataCheckString .= $key . '=' . $value . "\n";
        }

        $dataCheckString = rtrim($dataCheckString, "\n");

        // Compute secret key: HMAC_SHA256(bot_token, "WebAppData")
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

        // Compute HMAC of data-check-string
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($computedHash, $hash);
    }

    /**
     * Extract telegram user ID from initData.
     *
     * @return string|null Returns null if user data not found or invalid
     */
    public function appGetTelegramUserId(string $initData): ?string
    {
        if ($this->validateAppInitData($initData) === false) {
            return null;
        }

        $data = [];
        parse_str($initData, $data);

        // User data is JSON-encoded in the 'user' field
        $userJson = $data['user'] ?? '';
        if ($userJson === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $userData */
            $userData = json_decode($userJson, true, 512, JSON_THROW_ON_ERROR);
            $userId = (string) ($userData['id'] ?? '');

            if ($userId === '') {
                return null;
            }

            $user = $this->entityManager->getRepository(User::class)->findByTelegramId($userId);
            if ($user === null) {
                return null;
            }

            return (string) $userId;
        } catch (\JsonException) {
            return null;
        }
    }

    public function validateSecretToken(Request $request): void
    {
        $expectedSecret = $this->settings->get('telegram.webhook_secret');

        if (! is_string($expectedSecret) || $expectedSecret === '') {
            return;
        }

        $headerValue = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');

        if ($headerValue !== $expectedSecret) {
            throw new InvalidArgumentException('Invalid webhook secret token');
        }
    }
}
