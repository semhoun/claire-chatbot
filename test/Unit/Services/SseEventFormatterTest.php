<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\SseEventFormatter;
use PHPUnit\Framework\TestCase;

final class SseEventFormatterTest extends TestCase
{
    public function testFormatHtmlUpdateReturnsNativeEventSourceFormat(): void
    {
        $formatter = new SseEventFormatter();

        $result = $formatter->formatHtmlUpdate('messages', '<div>test</div>');

        $this->assertStringStartsWith('data: ', $result);
        $this->assertStringEndsWith("\n\n", $result);

        $json = json_decode(substr($result, 6), true);
        $this->assertSame(['html' => ['messages' => '<div>test</div>']], $json);
    }

    public function testFormatHtmlUpdateIncludesEventIdWhenProvided(): void
    {
        $formatter = new SseEventFormatter();

        $result = $formatter->formatHtmlUpdate('messages', '<div>test</div>', 'assistant-message-123');

        $this->assertStringStartsWith("id: assistant-message-123\n", $result);
        $this->assertStringContainsString("data: {\"html\":{\"messages\":\"<div>test</div>\"}}\n\n", $result);
    }

    public function testFormatJsExecReturnsNativeEventSourceFormat(): void
    {
        $formatter = new SseEventFormatter();

        $result = $formatter->formatJsExec('alert("test")');

        $this->assertStringStartsWith('data: ', $result);
        $this->assertStringEndsWith("\n\n", $result);

        $json = json_decode(substr($result, 6), true);
        $this->assertSame(['js' => ['exec' => 'alert("test")']], $json);
    }

    public function testLegacyFormatBuildsExpectedPayload(): void
    {
        $formatter = new SseEventFormatter();

        $payload = $formatter->format('chat.snapshot', "<div>first</div>\n<div>second</div>");

        $this->assertSame(
            "event: chat.snapshot\ndata: <div>first</div>\ndata: <div>second</div>\n\n",
            $payload,
        );
    }

    public function testKeepaliveReturnsCommentFrame(): void
    {
        $formatter = new SseEventFormatter();

        $this->assertSame(": keepalive\n\n", $formatter->keepalive());
    }

    public function testFormatJsonEventIncludesEventId(): void
    {
        $formatter = new SseEventFormatter();

        $result = $formatter->formatJsonEvent([
            'event' => 'message.assistant.start',
            'messageArticleId' => 'assistant-message-123',
        ], 'assistant-message-123');

        $this->assertSame(
            "id: assistant-message-123\ndata: {\"event\":\"message.assistant.start\",\"messageArticleId\":\"assistant-message-123\"}\n\n",
            $result,
        );
    }
}
