<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\MessageFormatter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class MessageFormatterTest extends TestCase
{
    public function testAddsStableIdsToHistoricalMessages(): void
    {
        $messages = new MessageFormatter([
            new UserMessage('Bonjour'),
            new AssistantMessage('Bonjour, comment puis-je vous aider ?'),
        ])->format();

        self::assertSame('history-message-0', $messages[0]['id']);
        self::assertSame('history-message-1', $messages[1]['id']);
    }
}
