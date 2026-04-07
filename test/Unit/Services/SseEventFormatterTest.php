<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\SseEventFormatter;
use PHPUnit\Framework\TestCase;

final class SseEventFormatterTest extends TestCase
{
    public function testFormatBuildsExpectedPayload(): void
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
}
