<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\SseEventFormatter;
use PHPUnit\Framework\TestCase;

final class SseEventFormatterTest extends TestCase
{
    public function testFormatNamedEventBuildsExpectedPayload(): void
    {
        $formatter = new SseEventFormatter();

        $payload = $formatter->formatNamedEvent('chat.snapshot', "<div>first</div>\n<div>second</div>");

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
            'messageArticleId' => 'assistant-message-123',
        ], 'assistant-message-123', 'message.assistant.start');

        $this->assertSame(
            "id: assistant-message-123\nevent: message.assistant.start\ndata: {\"messageArticleId\":\"assistant-message-123\"}\n\n",
            $result,
        );
    }
}
