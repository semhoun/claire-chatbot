<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\TelegramService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Phptg\BotApi\Type\Update;

final readonly class TelegramController
{
    public function __construct(
        private TelegramService $telegramService,
        private LoggerInterface $logger
    ) {
    }

    public function webhook(Request $request, Response $response): Response
    {
        try {
            $update = Update::fromArray((array) $request->getParsedBody());
            $this->telegramService->processUpdate($update);
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram Webhook Error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
            // Return 200 to Telegram to avoid infinite retries for application bugs
        }

        return $response->withStatus(200);
    }
}
