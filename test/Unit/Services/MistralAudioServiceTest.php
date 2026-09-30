<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Audio\MistralAudioService;
use App\Services\Settings;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;

final class MistralAudioServiceTest extends TestCase
{
    public function testTranscriptionMapsOpenAiParametersToMistral(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(self::callback(static function (HttpRequest $httpRequest): bool {
                $body = $httpRequest->body;

                return $httpRequest->uri === 'https://audio.test/v1/audio/transcriptions'
                    && $httpRequest->headers['Authorization'] === 'Bearer secret'
                    && $httpRequest->isMultipart()
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
        $httpClient->expects(self::once())
            ->method('request')
            ->with(self::callback(static function (HttpRequest $httpRequest): bool {
                $body = $httpRequest->body;

                return $httpRequest->uri === 'https://audio.test/v1/audio/speech'
                    && $httpRequest->headers['Authorization'] === 'Bearer secret'
                    && ! $httpRequest->isMultipart()
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
        $httpClient = $this->createStub(HttpClientInterface::class);
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

    public function testGuzzleEncodesMultipartFilenameAndRepeatedParametersWithoutNetwork(): void
    {
        $requests = [];
        $body = '';
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{"text":"Hello"}')]));
        $handler->push(Middleware::history($requests));
        $handler->push(Middleware::tap(static function ($request) use (&$body): void {
            $body = (string) $request->getBody();
        }));
        $service = new MistralAudioService($this->settings(), new GuzzleHttpClient(handler: $handler));

        $service->transcribe('audio bytes', 'voice.webm', 'audio/webm', [
            'prompt' => 'Claire', 'timestamp_granularities' => ['word', 'segment'],
        ]);

        $request = $requests[0]['request'];
        self::assertSame('https://audio.test/v1/audio/transcriptions', (string) $request->getUri());
        self::assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertStringContainsString('name="file"; filename="voice.webm"', $body);
        self::assertStringContainsString('Content-Type: audio/webm', $body);
        self::assertStringContainsString('audio bytes', $body);
        self::assertStringContainsString('name="context_bias"', $body);
        self::assertSame(2, substr_count($body, 'name="timestamp_granularities"'));
    }

    public function testMultipartFailurePropagatesAndClosesUploadResource(): void
    {
        $stream = null;
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(static function (HttpRequest $request) use (&$stream): never {
            $stream = $request->body['file']['contents'];
            throw new HttpException('Upstream failure', $request, new HttpResponse(429, '{"message":"Rate limited"}'));
        });

        try {
            (new MistralAudioService($this->settings(), $http))->transcribe('bytes', 'voice.webm', 'audio/webm');
            self::fail('Expected the provider failure');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->response->statusCode);
            self::assertFalse(is_resource($stream));
        }
    }

    public function testInvalidSpeechBase64IsRejected(): void
    {
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturn(new HttpResponse(200, '{"audio_data":"invalid!"}'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid audio data');
        (new MistralAudioService($this->settings(), $http))->speech('Hello', 'voice-1');
    }

    public function testMalformedTranscriptionJsonIsRejected(): void
    {
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturn(new HttpResponse(200, '{broken'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');
        (new MistralAudioService($this->settings(), $http))->transcribe('bytes', 'voice.webm', 'audio/webm');
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
