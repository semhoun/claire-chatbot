<?php

declare(strict_types=1);

namespace App\Controller;

use App\Queue\QueueDispatcherInterface;
use App\Services\Settings;
use App\Services\TelegramService;
use InvalidArgumentException;
use Phptg\BotApi\Type\Update\Update;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\NonBufferedBody;

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

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('cache-control', 'no-cache');

        try {
            $this->queueDispatcher->dispatch(
                TelegramService::class,
                ['update_json' => $rawBody],
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram Webhook Error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
            // Return 200 to Telegram to avoid infinite retries for application bugs
        }

        return $response;
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
