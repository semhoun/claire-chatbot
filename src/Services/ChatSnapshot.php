<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\ChatHistory\UserChatHistory;
use App\Renderer\ChatDataRenderer;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ChatSnapshot
{
    public function __construct(
        private ChatGenerationState $generationState,
        private EntityManagerInterface $entityManager,
        private ChatDataRenderer $renderer,
        private Settings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(SessionInterface $session, string $threadId): array
    {
        $userId = (string) $session->get(Auth::USERID);
        return $this->generationState->capture(
            $userId,
            $threadId,
            function (array $generation) use ($session, $threadId, $userId): array {
                $history = new UserChatHistory(
                    session: $session,
                    pdo: $this->entityManager->getConnection()->getNativeConnection(),
                    contextWindow: $this->settings->get('llm.openai.contextWindow'),
                    threadId: $threadId,
                    createIfMissing: false,
                );
                $messages = $history->getFormattedMessages();
                $last = array_key_last($messages);
                $messageId = $generation['messageId'] ?? '';
                if (($generation['status'] ?? '') === 'running' && $messageId !== '' && $last !== null
                    && ($messages[$last]['sent'] ?? true) === false
                    && str_starts_with((string) ($messages[$last]['id'] ?? ''), 'history-message-')
                    && ($messages[$last]['toolsCall'] ?? []) !== []) {
                    // An unfinished tool group has no final assistant metadata yet.
                    $messages[$last]['id'] = $messageId;
                }

                return [
                    'messages' => $this->renderer->messages(
                        $messages,
                        $userId,
                        ($generation['status'] ?? '') === 'running',
                    ),
                    'audioRequestIds' => array_column(array_filter(
                        $messages,
                        static fn (array $message): bool => isset($message['audioRequestId'])
                    ), 'audioRequestId', 'id'),
                ];
            },
        );
    }
}
