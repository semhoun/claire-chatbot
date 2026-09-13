<?php

declare(strict_types=1);

namespace App\Services;

readonly class ChatStreamPublisher
{
    public function __construct(
        private RedisClient $redisClient,
        private ChatStreamSubscriber $chatStreamSubscriber,
        private Settings $settings,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function publish(string $scope, string $event, array $payload): void
    {
        $channel = $this->chatStreamSubscriber->channel($scope);
        // Audio forwarding receives the internal token as sessionId; do not expose it to clients.
        $payload['sessionId'] = ChatStreamSubscriber::unScope($scope);

        $message = json_encode([
            'version' => 1,
            'event' => $event,
            'threadId' => $payload['threadId'] ?? '',
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        // Pub/Sub is ephemeral: zero listeners is a successful publication.
        if ($this->redisClient->publish($channel, $message) === false) {
            throw new \RuntimeException('Cannot publish chat stream event');
        }
    }

    public function generationState(): ChatGenerationState
    {
        return new ChatGenerationState($this->redisClient, $this->settings);
    }
}
