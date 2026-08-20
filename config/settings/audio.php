<?php

declare(strict_types=1);

use App\Services\Env;

$voicesJson = (string) Env::get('MISTRAL_AUDIO_VOICES', '[]');

try {
    $decodedVoices = json_decode($voicesJson, true, flags: JSON_THROW_ON_ERROR);
} catch (\JsonException $jsonException) {
    throw new \RuntimeException(
        'MISTRAL_AUDIO_VOICES must contain valid JSON',
        $jsonException->getCode(),
        $jsonException,
    );
}

$voices = [];
foreach (is_array($decodedVoices) ? $decodedVoices : [] as $voice) {
    if (! is_array($voice)) {
        continue;
    }

    $id = trim((string) ($voice['id'] ?? ''));
    $label = trim((string) ($voice['label'] ?? ''));
    if ($id !== '' && $label !== '') {
        $voices[] = ['id' => $id, 'label' => $label];
    }
}

$defaultVoice = trim((string) Env::get(
    'MISTRAL_AUDIO_DEFAULT_VOICE',
    $voices[0]['id'] ?? '',
));

return [
    'enabled' => Env::get('MISTRAL_AUDIO_ENABLED', false) === true,
    'baseUri' => Env::get('MISTRAL_AUDIO_API_URL', 'https://api.mistral.ai/v1'),
    'key' => Env::get('MISTRAL_AUDIO_API_KEY'),
    'transcriptionModel' => Env::get(
        'MISTRAL_AUDIO_TRANSCRIPTION_MODEL',
        'voxtral-mini-latest',
    ),
    'speechModel' => Env::get(
        'MISTRAL_AUDIO_SPEECH_MODEL',
        'voxtral-mini-tts-2603',
    ),
    'voices' => $voices,
    'defaultVoice' => $defaultVoice,
    'maxUploadBytes' => 500_000_000,
    'maxRecordingSeconds' => (int) Env::get('MISTRAL_AUDIO_MAX_RECORDING_SECONDS', 300),
];
