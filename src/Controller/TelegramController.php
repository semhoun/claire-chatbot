<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Settings;
use App\Services\TelegramService;
use InvalidArgumentException;
use Phptg\BotApi\Type\Update\Update;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;

final readonly class TelegramController
{
    public function __construct(
        private QueueDispatcherInterface $queueDispatcher,
        private Logger $logger,
        private Settings $settings,
    ) {
    }

    public function webhook(Request $request, Response $response): Response
    {
        try {
            $this->validateSecretToken($request);
            $rawBody = $request->getBody()->getContents();
            Update::fromJson($rawBody);
        } catch (InvalidArgumentException) {
            return $response->withStatus(401);
        }

        try {
            $this->queueDispatcher->dispatch(
                TelegramService::class,
                ['update_json' => $rawBody],
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram Webhook Error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
        }

        return $response->withStatus(204);
    }

    private function validateSecretToken(Request $request): void
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
