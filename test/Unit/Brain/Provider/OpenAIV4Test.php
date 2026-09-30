<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Provider;

use App\Brain\Provider\MessageMapper;
use App\Brain\Provider\OpenAI;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

final class OpenAIV4Test extends TestCase
{
    public function testDirectProviderReturnsResponseAndMapsSystemTextImagesAndFiles(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(
            static function (HttpRequest $request): HttpResponse {
                self::assertSame('https://provider.test/v1/chat/completions', $request->uri);
                self::assertSame('Bearer test-key', $request->headers['Authorization']);
                self::assertSame('test-model', $request->body['model']);
                self::assertSame('System instructions', $request->body['messages'][0]['content'][0]['text']);
                $blocks = $request->body['messages'][1]['content'];
                self::assertStringContainsString('name="notes.txt" type="text/plain">Notes</file>', $blocks[0]['text']);
                self::assertStringContainsString('type="application/pdf" encoding="base64">cGRm</file>', $blocks[0]['text']);
                self::assertSame(['type' => 'text', 'text' => 'Read these'], $blocks[1]);
                self::assertSame('data:image/png;base64,aW1hZ2U=', $blocks[2]['image_url']['url']);
                self::assertSame('https://image.test/a.png', $blocks[3]['image_url']['url']);

                return new HttpResponse(200, json_encode([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'Read successfully'],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
                ], JSON_THROW_ON_ERROR), ['x-request-id' => ['request-1']]);
            },
        );
        $provider = new OpenAI('https://provider.test/v1', 'test-key', 'test-model', ['text/plain'], httpClient: $http);
        $response = $provider->systemPrompt(new SystemMessage('System instructions'))->chat(new UserMessage([
            new TextContent('Read these'),
            new ImageContent(base64_encode('image'), SourceType::BASE64, 'image/png'),
            new ImageContent('https://image.test/a.png', SourceType::URL, 'image/png'),
            new FileContent(base64_encode('Notes'), SourceType::BASE64, 'text/plain', 'notes.txt'),
            new FileContent(base64_encode('pdf'), SourceType::BASE64, 'application/pdf', 'report.pdf'),
        ]));

        self::assertInstanceOf(ProviderResponse::class, $response);
        self::assertSame('Read successfully', $response->message()->getContent());
        self::assertSame(['x-request-id' => ['request-1']], $response->headers());
        self::assertSame('test-model', $provider->getModel());
    }

    public function testToolCallRecordsMapWithoutExecutableTools(): void
    {
        $call = ToolCall::make('generate_pdf', 'call-pdf', ['content' => 'Report']);
        $result = (clone $call)->setResult('{"id":"@@GENERATED@@report@@"}');
        $mapped = (new MessageMapper([]))->map([
            new ToolCallMessage('Preparing report', [$call]),
            new ToolResultMessage([$result]),
        ]);

        self::assertSame('call-pdf', $mapped[0]['tool_calls'][0]['id']);
        self::assertSame('generate_pdf', $mapped[0]['tool_calls'][0]['function']['name']);
        self::assertSame('{"content":"Report"}', $mapped[0]['tool_calls'][0]['function']['arguments']);
        self::assertSame('Preparing report', $mapped[0]['content'][0]['text']);
        self::assertSame('call-pdf', $mapped[1]['tool_call_id']);
        self::assertSame('{"id":"@@GENERATED@@report@@"}', $mapped[1]['content']);
    }

    public function testInvalidRawBase64FailsBeforeProviderRequest(): void
    {
        $this->expectException(ProviderException::class);
        (new MessageMapper(['text/plain']))->map([
            new UserMessage(new FileContent('invalid!', SourceType::BASE64, 'text/plain', 'notes.txt')),
        ]);
    }
}
