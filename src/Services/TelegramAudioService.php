<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\File;
use App\Enums\TelegramAction;
use App\Services\Audio\AudioServiceInterface;
use Phptg\BotApi\FailResult;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\InputFile;
use Phptg\BotApi\Type\Voice;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Throwable;

final readonly class TelegramAudioService
{
    public function __construct(
        private TelegramBotApi $telegramBotApi,
        private AudioServiceInterface $audioService,
        private Logger $logger,
    ) {
    }

    public function transcribe(Voice $voice): string
    {
        $file = $this->telegramBotApi->getFile(fileId: $voice->fileId);
        if ($file instanceof FailResult) {
            throw new RuntimeException('Telegram could not resolve the voice file');
        }

        $audio = $this->telegramBotApi->downloadFile($file)->getBody();
        $transcription = $this->audioService->transcribe(
            $audio,
            'voice.ogg',
            $voice->mimeType ?? 'audio/ogg',
        );
        $text = trim((string) ($transcription['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('The voice transcription is empty');
        }

        return $text;
    }

    public function sendResponse(int $telegramChatId, string $responseText, string $voice): void
    {
        $text = $this->normalizeSpeechText($responseText);
        if ($text === '') {
            return;
        }

        try {
            foreach ($this->splitSpeechText($text) as $chunk) {
                $this->telegramBotApi->sendChatAction(
                    $telegramChatId,
                    TelegramAction::VOICE->value,
                );
                $speech = $this->audioService->speech($chunk, $voice, 'opus');
                $this->sendVoice($telegramChatId, $speech->content);
            }
        } catch (Throwable $throwable) {
            $this->logger->error('Voice response error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
        }
    }

    private function sendVoice(int $telegramChatId, string $audio): void
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to create Telegram voice stream');
        }

        try {
            fwrite($stream, $audio);
            rewind($stream);

            $result = $this->telegramBotApi->sendVoice(
                chatId: $telegramChatId,
                voice: new InputFile($stream, 'claire.ogg'),
            );
            if ($result instanceof FailResult) {
                throw new RuntimeException('Telegram rejected the generated voice');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
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

    /** @return list<string> */
    private function splitSpeechText(string $text): array
    {
        $words = preg_split('/\s+/u', $text, flags: PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [$text];
        }

        return array_map(
            static fn (array $chunk): string => implode(' ', $chunk),
            array_chunk($words, 300),
        );
    }
}
