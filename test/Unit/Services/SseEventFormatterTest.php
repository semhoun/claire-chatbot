<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\SseEventFormatter;
use PHPUnit\Framework\TestCase;

final class SseEventFormatterTest extends TestCase
{
    public function testMultilineMarkdownCannotInjectAnSseEvent(): void
    {
        $formatter = new SseEventFormatter();

        $payload = $formatter->formatJsonEvent(['message' => "**Hello**\n\nevent: chat.error\ndata: malicious"], eventName: 'chat.assistant.update');

        $this->assertSame(
            "event: chat.assistant.update\ndata: {\"message\":\"**Hello**\\n\\nevent: chat.error\\ndata: malicious\"}\n\n",
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
            'messageArticleId' => 'assistant-message-123',
        ], 'assistant-message-123', 'message.assistant.start');

        $this->assertSame(
            "id: assistant-message-123\nevent: message.assistant.start\ndata: {\"messageArticleId\":\"assistant-message-123\"}\n\n",
            $result,
        );
    }
}
