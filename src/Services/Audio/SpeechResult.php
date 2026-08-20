<?php

declare(strict_types=1);

namespace App\Services\Audio;

final readonly class SpeechResult
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $extension,
    ) {
    }
}
