<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Audio\MistralAudioService;
use App\Services\Settings;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;

final class MistralAudioServiceTest extends TestCase
{
    public function testTranscriptionMapsOpenAiParametersToMistral(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->configureClient($httpClient);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(self::callback(static function (HttpRequest $httpRequest): bool {
                $body = $httpRequest->body;

                return $httpRequest->uri === 'audio/transcriptions'
                    && is_array($body)
                    && $body['model'] === 'fixed-stt-model'
                    && $body['language'] === 'fr'
                    && in_array([
                        'name' => 'context_bias',
                        'contents' => 'Claire et Neuron AI',
                    ], $body, true)
                    && in_array([
                        'name' => 'timestamp_granularities',
                        'contents' => 'word',
                    ], $body, true)
                    && is_resource($body['file']['contents']);
            }))
            ->willReturn(new HttpResponse(200, json_encode([
                'text' => 'Bonjour',
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3],
            ], JSON_THROW_ON_ERROR)));

        $mistralAudioService = new MistralAudioService($this->settings(), $httpClient);
        $result = $mistralAudioService->transcribe(
            'audio bytes',
            'voice.webm',
            'audio/webm',
            [
                'language' => 'fr',
                'prompt' => 'Claire et Neuron AI',
                'timestamp_granularities' => ['word'],
            ],
        );

        self::assertSame('Bonjour', $result['text']);
    }

    public function testSpeechMapsVoiceAndDecodesAudio(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->configureClient($httpClient);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(self::callback(static function (HttpRequest $httpRequest): bool {
                $body = $httpRequest->body;

                return $httpRequest->uri === 'audio/speech'
                    && is_array($body)
                    && $body['model'] === 'fixed-tts-model'
                    && $body['voice_id'] === 'voice-1'
                    && $body['response_format'] === 'opus';
            }))
            ->willReturn(new HttpResponse(200, json_encode([
                'audio_data' => base64_encode('opus bytes'),
            ], JSON_THROW_ON_ERROR)));

        $speechResult = new MistralAudioService($this->settings(), $httpClient)
            ->speech('Bonjour', 'voice-1', 'opus');

        self::assertSame('opus bytes', $speechResult->content);
        self::assertSame('audio/opus', $speechResult->mimeType);
        self::assertSame('opus', $speechResult->extension);
    }

    public function testTranscriptionToleratesHttpClientClosingUploadStream(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->configureClient($httpClient);
        $httpClient->method('request')->willReturnCallback(
            static function (HttpRequest $httpRequest): HttpResponse {
                $body = $httpRequest->body;
                self::assertIsArray($body);
                self::assertIsResource($body['file']['contents']);

                fclose($body['file']['contents']);

                return new HttpResponse(200, json_encode([
                    'text' => 'Flux déjà fermé',
                ], JSON_THROW_ON_ERROR));
            },
        );

        $result = new MistralAudioService($this->settings(), $httpClient)
            ->transcribe('audio bytes', 'voice.webm', 'audio/webm');

        self::assertSame('Flux déjà fermé', $result['text']);
    }

    private function configureClient(HttpClientInterface $httpClient): void
    {
        $httpClient->method('withBaseUri')->willReturnSelf();
        $httpClient->method('withHeaders')->willReturnSelf();
    }

    private function settings(): Settings
    {
        return new Settings([
            'audio' => [
                'enabled' => true,
                'baseUri' => 'https://audio.test/v1',
                'key' => 'secret',
                'transcriptionModel' => 'fixed-stt-model',
                'speechModel' => 'fixed-tts-model',
                'voices' => [['id' => 'voice-1', 'label' => 'Claire']],
                'defaultVoice' => 'voice-1',
                'maxUploadBytes' => 500_000_000,
                'maxRecordingSeconds' => 300,
            ],
            'llm' => [
                'httpClient' => ['timeout' => 30.0, 'connectTimeout' => 5.0],
            ],
        ]);
    }
}
