<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Services\Settings;
use function base64_decode;
use function fclose;
use function fopen;
use function fwrite;
use JsonException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use function rewind;
use RuntimeException;

final readonly class MistralAudioService implements AudioServiceInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private Settings $settings,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient(
            timeout: $settings->get('llm.httpClient.timeout'),
            connectTimeout: $settings->get('llm.httpClient.connectTimeout'),
        ))
            ->withBaseUri((string) $settings->get('audio.baseUri'))
            ->withHeaders([
                'Authorization' => 'Bearer ' . $settings->get('audio.key'),
            ]);
    }

    public function isAvailable(): bool
    {
        return $this->settings->get('audio.enabled') === true
            && trim((string) $this->settings->get('audio.key')) !== ''
            && $this->voices() !== []
            && $this->isAllowedVoice($this->defaultVoice());
    }

    /** @return list<array{id: string, label: string}> */
    public function voices(): array
    {
        return $this->settings->get('audio.voices');
    }

    public function defaultVoice(): string
    {
        return (string) $this->settings->get('audio.defaultVoice');
    }

    public function transcriptionModel(): string
    {
        return (string) $this->settings->get('audio.transcriptionModel');
    }

    public function speechModel(): string
    {
        return (string) $this->settings->get('audio.speechModel');
    }

    public function maxUploadBytes(): int
    {
        return (int) $this->settings->get('audio.maxUploadBytes');
    }

    public function maxRecordingSeconds(): int
    {
        return (int) $this->settings->get('audio.maxRecordingSeconds');
    }

    public function isAllowedVoice(string $voice): bool
    {
        return array_any(
            $this->voices(),
            static fn (array $configuredVoice): bool => $configuredVoice['id'] === $voice,
        );
    }

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
    ): array {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the audio upload stream');
        }

        try {
            fwrite($stream, $audio);
            rewind($stream);

            $body = [
                'file' => [
                    'contents' => $stream,
                    'filename' => $filename,
                    'headers' => ['Content-Type' => $mediaType],
                ],
                'model' => $this->transcriptionModel(),
            ];

            foreach (['language', 'temperature'] as $parameter) {
                if (isset($parameters[$parameter])) {
                    $body[$parameter] = $parameters[$parameter];
                }
            }

            $prompt = trim((string) ($parameters['prompt'] ?? ''));
            if ($prompt !== '') {
                $body[] = ['name' => 'context_bias', 'contents' => $prompt];
            }

            foreach ((array) ($parameters['timestamp_granularities'] ?? []) as $granularity) {
                $body[] = [
                    'name' => 'timestamp_granularities',
                    'contents' => (string) $granularity,
                ];
            }

            $response = $this->httpClient->request(HttpRequest::post(
                uri: 'audio/transcriptions',
                body: $body,
            ));

            return $this->decodeJson($response->body);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function speech(
        string $input,
        string $voice,
        string $format = 'mp3',
    ): SpeechResult {
        if (! $this->isAllowedVoice($voice)) {
            throw new \InvalidArgumentException('Unknown audio voice');
        }

        $formats = [
            'mp3' => 'audio/mpeg',
            'opus' => 'audio/opus',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'pcm' => 'application/octet-stream',
        ];
        if (! isset($formats[$format])) {
            throw new \InvalidArgumentException('Unsupported audio response format');
        }

        $httpResponse = $this->httpClient->request(HttpRequest::post(
            uri: 'audio/speech',
            body: [
                'model' => $this->speechModel(),
                'input' => $input,
                'voice_id' => $voice,
                'response_format' => $format,
                'stream' => false,
            ],
            headers: ['Content-Type' => 'application/json'],
        ));

        $payload = $this->decodeJson($httpResponse->body);
        $audio = base64_decode((string) ($payload['audio_data'] ?? ''), true);
        if ($audio === false || $audio === '') {
            throw new RuntimeException('Mistral returned invalid audio data');
        }

        return new SpeechResult($audio, $formats[$format], $format);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $body): array
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(
                'Mistral returned invalid JSON',
                $jsonException->getCode(),
                $jsonException,
            );
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Mistral returned an invalid response');
        }

        return $payload;
    }
}
