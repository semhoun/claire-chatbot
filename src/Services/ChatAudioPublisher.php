<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\File;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Session\SessionInterface;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

final readonly class ChatAudioPublisher
{
    public function __construct(
        private AudioServiceInterface $audioService,
        private ChatStreamPublisher $chatStreamPublisher,
        private Logger $logger,
    ) {
    }

    public function publish(
        string $sessionId,
        string $threadId,
        string $messageId,
        string $responseText,
        SessionInterface $session,
    ): void {
        if (! $this->audioService->isAvailable()
            || $session->get(AudioServiceInterface::ENABLED_SESSION_KEY, false) !== true) {
            return;
        }

        try {
            $voice = $this->resolveVoice($session);
            $speech = $this->audioService->speech(
                $this->normalizeSpeechText($responseText),
                $voice,
                'mp3',
            );
            $this->chatStreamPublisher->publish($sessionId, 'chat.audio.ready', [
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'messageId' => $messageId,
                'mimeType' => $speech->mimeType,
                'audioData' => base64_encode($speech->content),
            ]);
        } catch (Throwable $throwable) {
            $this->logger->error('Chat audio generation failed', [
                'exception' => $throwable,
                'threadId' => $threadId,
                'messageId' => $messageId,
            ]);
            $this->chatStreamPublisher->publish($sessionId, 'chat.audio.error', [
                'threadId' => $threadId,
                'sessionId' => $sessionId,
                'messageId' => $messageId,
            ]);
        }
    }

    private function resolveVoice(SessionInterface $session): string
    {
        $voice = (string) $session->get(
            AudioServiceInterface::VOICE_SESSION_KEY,
            $this->audioService->defaultVoice(),
        );

        return $this->audioService->isAllowedVoice($voice)
            ? $voice
            : $this->audioService->defaultVoice();
    }

    private function normalizeSpeechText(string $text): string
    {
        $text = (string) preg_replace(File::GENERATED_FILE_PATTERN, '', $text);
        $text = (string) preg_replace('/```(?:[^`]++|`(?!``))*```/s', ' ', $text);
        $text = (string) preg_replace('/\[([^]]+)]\([^)]+\)/', '$1', $text);
        $text = str_replace(['`', '*', '_', '#', '>', '~'], '', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
