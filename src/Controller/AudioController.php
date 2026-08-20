<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Audio\AudioServiceInterface;
use InvalidArgumentException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface as Logger;
use Throwable;

final readonly class AudioController
{
    private const array TRANSCRIPTION_FORMATS = ['json', 'verbose_json', 'text'];

    private const array TRANSCRIPTION_EXTENSIONS = ['wav', 'mp3', 'flac', 'ogg', 'webm'];

    public function __construct(
        private AudioServiceInterface $audioService,
        private Logger $logger,
    ) {
    }

    public function transcriptions(Request $request, Response $response): Response
    {
        if (! $this->audioService->isAvailable()) {
            return $this->error($response, 'Audio is not configured', 'server_error', null, 'audio_unavailable', 503);
        }

        try {
            $data = (array) ($request->getParsedBody() ?? []);
            $this->requiredString($data, 'model');

            if (filter_var($data['stream'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new InvalidArgumentException('Streaming transcription is not supported');
            }

            $responseFormat = (string) ($data['response_format'] ?? 'json');
            if (! in_array($responseFormat, self::TRANSCRIPTION_FORMATS, true)) {
                throw new InvalidArgumentException('Unsupported transcription response format');
            }

            $file = $request->getUploadedFiles()['file'] ?? null;
            if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                return $this->error($response, 'A valid audio file is required', 'invalid_request_error', 'file', 'invalid_file', 400);
            }

            $this->validateAudioFile($file);
            $audio = $this->readUploadedFile($file);
            $result = $this->audioService->transcribe(
                $audio,
                $file->getClientFilename() ?? 'audio',
                $file->getClientMediaType() ?? 'application/octet-stream',
                $this->transcriptionParameters($data),
            );

            return $this->transcriptionResponse($response, $result, $responseFormat);
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, $exception->getMessage(), 'invalid_request_error', null, 'invalid_request', 400);
        } catch (Throwable $throwable) {
            return $this->upstreamError($response, $throwable);
        }
    }

    public function speech(Request $request, Response $response): Response
    {
        if (! $this->audioService->isAvailable()) {
            return $this->error($response, 'Audio is not configured', 'server_error', null, 'audio_unavailable', 503);
        }

        try {
            $data = (array) ($request->getParsedBody() ?? []);
            $input = $this->requiredString($data, 'input');
            $this->requiredString($data, 'model');
            $voice = $this->requiredString($data, 'voice');

            if (strlen($input) > 4096) {
                return $this->error($response, 'Input must contain at most 4096 characters', 'invalid_request_error', 'input', 'input_too_long', 400);
            }

            if (! $this->audioService->isAllowedVoice($voice)) {
                return $this->error($response, 'Unknown audio voice', 'invalid_request_error', 'voice', 'invalid_voice', 400);
            }

            $format = (string) ($data['response_format'] ?? 'mp3');
            $speed = (float) ($data['speed'] ?? 1.0);
            if ($speed !== 1.0) {
                throw new InvalidArgumentException('Speech speed is not supported by the configured provider');
            }

            if (trim((string) ($data['instructions'] ?? '')) !== '') {
                throw new InvalidArgumentException('Speech instructions are not supported by the configured provider');
            }

            if (filter_var($data['stream'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new InvalidArgumentException('Streaming speech is not supported');
            }

            $speech = $this->audioService->speech($input, $voice, $format);
            $response->getBody()->write($speech->content);

            return $response
                ->withHeader('Content-Type', $speech->mimeType)
                ->withHeader('Content-Length', (string) strlen($speech->content))
                ->withHeader('Content-Disposition', 'inline; filename="speech.' . $speech->extension . '"');
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, $exception->getMessage(), 'invalid_request_error', null, 'invalid_request', 400);
        } catch (Throwable $throwable) {
            return $this->upstreamError($response, $throwable);
        }
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $field): string
    {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s is required', $field));
        }

        return $value;
    }

    private function validateAudioFile(UploadedFileInterface $uploadedFile): void
    {
        $size = $uploadedFile->getSize();
        if ($size !== null && $size > $this->audioService->maxUploadBytes()) {
            throw new InvalidArgumentException('Audio file is too large');
        }

        $extension = strtolower(pathinfo($uploadedFile->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if (! in_array($extension, self::TRANSCRIPTION_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Unsupported audio file format');
        }
    }

    private function readUploadedFile(UploadedFileInterface $uploadedFile): string
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();

        $content = $stream->getContents();

        if ($content === '') {
            throw new InvalidArgumentException('Audio file is empty');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function transcriptionParameters(array $data): array
    {
        $parameters = [];
        foreach (['language', 'prompt', 'temperature'] as $name) {
            if (isset($data[$name]) && $data[$name] !== '') {
                $parameters[$name] = $data[$name];
            }
        }

        if (isset($data['timestamp_granularities'])) {
            $granularities = array_values((array) $data['timestamp_granularities']);
            foreach ($granularities as $granularity) {
                if (! in_array($granularity, ['segment', 'word'], true)) {
                    throw new InvalidArgumentException('Unsupported timestamp granularity');
                }
            }

            $parameters['timestamp_granularities'] = $granularities;
        }

        return $parameters;
    }

    /** @param array<string, mixed> $result */
    private function transcriptionResponse(
        Response $response,
        array $result,
        string $format,
    ): Response {
        $text = (string) ($result['text'] ?? '');
        if ($text === '') {
            throw new \RuntimeException('Mistral returned an empty transcription');
        }

        if ($format === 'text') {
            $response->getBody()->write($text);
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $payload = ['text' => $text];
        if ($format === 'verbose_json') {
            $payload += [
                'language' => $result['language'] ?? null,
                'duration' => $result['usage']['prompt_audio_seconds'] ?? null,
                'segments' => $result['segments'] ?? [],
            ];
        }

        if (is_array($result['usage'] ?? null)) {
            $payload['usage'] = $this->normalizeUsage($result['usage']);
        }

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array<string, int|string>
     */
    private function normalizeUsage(array $usage): array
    {
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        return [
            'type' => 'tokens',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => (int) ($usage['total_tokens'] ?? $inputTokens + $outputTokens),
        ];
    }

    private function upstreamError(Response $response, Throwable $throwable): Response
    {
        $this->logger->error('Audio provider request failed', ['exception' => $throwable]);

        $status = 502;
        $message = 'The audio provider request failed';
        if ($throwable instanceof HttpException && $throwable->response instanceof HttpResponse) {
            $upstreamStatus = $throwable->response->statusCode;
            if ($upstreamStatus >= 400 && $upstreamStatus < 600) {
                $status = $upstreamStatus;
            }

            try {
                $payload = json_decode($throwable->response->body, true, flags: JSON_THROW_ON_ERROR);
                $message = (string) ($payload['message'] ?? $payload['error']['message'] ?? $message);
            } catch (\JsonException) {
                $this->logger->debug('Audio provider returned a non-JSON error body');
            }
        }

        return $this->error($response, $message, 'api_error', null, 'provider_error', $status);
    }

    private function error(
        Response $response,
        string $message,
        string $type,
        ?string $parameter,
        string $code,
        int $status,
    ): Response {
        $response->getBody()->write(json_encode([
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => $parameter,
                'code' => $code,
            ],
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
