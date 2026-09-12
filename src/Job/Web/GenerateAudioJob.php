<?php

declare(strict_types=1);

namespace App\Job\Web;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\ChatAudioPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\Queue\NonRetryableJobException;
use App\Services\Queue\QueueDoer;
use App\Services\Session\InMemorySession;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final readonly class GenerateAudioJob implements QueueDoer
{
    public function __construct(private ChatAudioPublisher $chatAudioPublisher)
    {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $audioRequestId = $payload['audioRequestId'] ?? null;
        if (! is_string($audioRequestId)
            || preg_match(UserChatHistory::AUDIO_REQUEST_ID_PATTERN, $audioRequestId) !== 1) {
            throw new NonRetryableJobException('Obsolete manual audio job: missing or invalid audioRequestId');
        }

        $sessionId = $this->requiredString($payload, 'sessionId');
        $threadId = $this->requiredString($payload, 'threadId');
        $messageId = $this->requiredString($payload, 'messageId');
        $text = $this->requiredString($payload, 'text');
        $session = $payload['session'] ?? null;
        if (! is_array($session)) {
            throw new InvalidArgumentException('Session is required');
        }

        $this->chatAudioPublisher->publish(
            ChatStreamSubscriber::scope((string) ($session[Auth::USERID] ?? ''), $sessionId),
            $threadId,
            $messageId,
            $text,
            new InMemorySession($session),
            $audioRequestId,
        );
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s is required', $key));
        }

        return $value;
    }
}
