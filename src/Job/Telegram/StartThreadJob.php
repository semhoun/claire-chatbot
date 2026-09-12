<?php

declare(strict_types=1);

namespace App\Job\Telegram;

use App\Services\Queue\QueueDoer;
use App\Services\TelegramService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;

/**
 * Handles the processing of streaming chat messages in a web-based real-time chat system.
 *
 * This class is utilized to process user messages, handle chat state, stream agent responses,
 * manage message attachments, and publish updates to a chat stream through a publisher service.
 *
 * Responsibilities include:
 * - Initializing the context for chat processing with user, session, and message data.
 * - Streaming agent responses to the user while managing asynchronous chunks of text or tools.
 * - Handling formatting and publishing of updates for tool usage and user-facing text chunks.
 * - Managing error states and ensuring appropriate feedback is provided to the user when issues occur.
 * - Finalizing the chat stream once all processing is complete.
 *
 * Implements the `QueueDoer` interface to integrate with a queue job execution system.
 */
final readonly class StartThreadJob implements QueueDoer
{
    public function __construct(
        private Logger $logger,
        private TelegramService $telegramService,
    ) {
    }

    public static function make(ContainerInterface $container): self
    {
        return new StartThreadJob($container->get(Logger::class), $container->get(TelegramService::class));
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $telegramUserId = (string) ($payload['telegramUserId'] ?? '');
        try {
            if ($telegramUserId === '') {
                throw new \InvalidArgumentException('Thread ID cannot be empty');
            }

            $this->telegramService->startNewChatForUser($telegramUserId, (string) ($payload['generationId'] ?? ''));
        } catch (\Throwable $throwable) {
            $this->logger->error('Telegram start new thread processing error', ['throwable' => $throwable]);
            throw $throwable;
        }
    }
}
