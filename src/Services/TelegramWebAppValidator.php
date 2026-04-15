<?php

declare(strict_types=1);

namespace App\Services;

final class TelegramWebAppValidator
{
    /**
     * Validate Telegram WebApp initData HMAC signature.
     *
     * The initData is validated using HMAC-SHA256 with the bot token.
     * The key is computed as: HMAC_SHA256(bot_token, "WebAppData")
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    public function validateInitData(string $initData, string $botToken): bool
    {
        if ($initData === '' || $botToken === '') {
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
     * Extract user ID from initData.
     *
     * @return string|null Returns null if user data not found or invalid
     */
    public function extractUserId(string $initData): ?string
    {
        if ($initData === '') {
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
            $userId = $userData['id'] ?? null;

            return is_int($userId) || is_string($userId) ? (string) $userId : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Extract user data from initData.
     *
     * @return array<string, mixed>|null Returns null if user data not found or invalid
     */
    public function extractUserData(string $initData): ?array
    {
        if ($initData === '') {
            return null;
        }

        $data = [];
        parse_str($initData, $data);

        $userJson = $data['user'] ?? '';
        if ($userJson === '') {
            return null;
        }

        try {
            return json_decode($userJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Check if initData is not expired (optional security check).
     *
     * @param int $maxAgeSeconds Maximum age in seconds (default: 24 hours)
     */
    public function isInitDataFresh(string $initData, int $maxAgeSeconds = 86400): bool
    {
        $data = [];
        parse_str($initData, $data);

        $authDate = $data['auth_date'] ?? 0;
        if ($authDate === 0) {
            return false;
        }

        $now = time();
        $authTime = (int) $authDate;

        return $now - $authTime <= $maxAgeSeconds;
    }
}
