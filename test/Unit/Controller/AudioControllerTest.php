<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\AudioController;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Audio\SpeechResult;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class AudioControllerTest extends TestCase
{
    public function testSpeechReturnsOpenAiCompatibleBinaryResponse(): void
    {
        $service = $this->availableService();
        $service->expects(self::once())
            ->method('speech')
            ->with('Bonjour', 'voice-1', 'mp3')
            ->willReturn(new SpeechResult('mp3 bytes', 'audio/mpeg', 'mp3'));
        $controller = new AudioController($service, $this->createStub(LoggerInterface::class));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v1/audio/speech')
            ->withParsedBody([
                'input' => 'Bonjour',
                'model' => 'client-model',
                'voice' => 'voice-1',
                'response_format' => 'mp3',
            ]);

        $response = $controller->speech($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('audio/mpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame('mp3 bytes', (string) $response->getBody());
    }

    public function testTranscriptionReturnsNormalizedJson(): void
    {
        $service = $this->availableService();
        $service->expects(self::once())
            ->method('transcribe')
            ->willReturn([
                'text' => 'Bonjour Claire',
                'usage' => [
                    'prompt_tokens' => 2,
                    'completion_tokens' => 4,
                    'total_tokens' => 6,
                ],
            ]);
        $controller = new AudioController($service, $this->createStub(LoggerInterface::class));
        $stream = (new StreamFactory())->createStream('audio bytes');
        $file = new UploadedFile($stream, 'voice.webm', 'audio/webm', 11);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v1/audio/transcriptions')
            ->withParsedBody(['model' => 'client-model', 'response_format' => 'json'])
            ->withUploadedFiles(['file' => $file]);

        $response = $controller->transcriptions(
            $request,
            (new ResponseFactory())->createResponse(),
        );
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Bonjour Claire', $payload['text']);
        self::assertSame(6, $payload['usage']['total_tokens']);
    }

    public function testUnavailableAudioUsesOpenAiErrorEnvelope(): void
    {
        $service = $this->createStub(AudioServiceInterface::class);
        $service->method('isAvailable')->willReturn(false);
        $controller = new AudioController($service, $this->createStub(LoggerInterface::class));

        $response = $controller->speech(
            (new ServerRequestFactory())->createServerRequest('POST', '/v1/audio/speech'),
            (new ResponseFactory())->createResponse(),
        );
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('audio_unavailable', $payload['error']['code']);
    }

    public function testV4HttpFailurePreservesUpstreamStatusAndErrorEnvelope(): void
    {
        $service = $this->availableService();
        $service->expects(self::once())->method('transcribe')->willThrowException(new HttpException(
            'Upstream failure',
            HttpRequest::post('https://audio.test/v1/audio/transcriptions'),
            new HttpResponse(429, '{"error":{"message":"Rate limited"}}'),
        ));
        $controller = new AudioController($service, $this->createStub(LoggerInterface::class));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v1/audio/transcriptions')
            ->withParsedBody(['model' => 'client-model'])
            ->withUploadedFiles(['file' => new UploadedFile(
                (new StreamFactory())->createStream('audio bytes'), 'voice.webm', 'audio/webm', 11,
            )]);

        $response = $controller->transcriptions($request, (new ResponseFactory())->createResponse());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('Rate limited', $payload['error']['message']);
        self::assertSame('provider_error', $payload['error']['code']);
    }

    private function availableService(): AudioServiceInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $service = $this->createMock(AudioServiceInterface::class);
        $service->method('isAvailable')->willReturn(true);
        $service->method('isAllowedVoice')->willReturnCallback(
            static fn (string $voice): bool => $voice === 'voice-1',
        );
        $service->method('maxUploadBytes')->willReturn(500_000_000);

        return $service;
    }
}
