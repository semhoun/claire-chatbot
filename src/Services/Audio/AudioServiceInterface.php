<?php

declare(strict_types=1);

namespace App\Services\Audio;

interface AudioServiceInterface
{
    public const string ENABLED_SESSION_KEY = 'audio_enabled';

    public const string AUTO_GENERATE_SESSION_KEY = 'audio_auto_generate';

    public const string DICTATION_MODE_SESSION_KEY = 'audio_dictation_mode';

    public const string VOICE_SESSION_KEY = 'audio_voice';

    public function isAvailable(): bool;

    /** @return list<array{id: string, label: string}> */
    public function voices(): array;

    public function defaultVoice(): string;

    public function transcriptionModel(): string;

    public function speechModel(): string;

    public function maxUploadBytes(): int;

    public function maxRecordingSeconds(): int;

    public function isAllowedVoice(string $voice): bool;

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function transcribe(
        string $audio,
        string $filename,
        string $mediaType,
        array $parameters = [],
    ): array;

    public function speech(
        string $input,
        string $voice,
        string $format = 'mp3',
    ): SpeechResult;
}
