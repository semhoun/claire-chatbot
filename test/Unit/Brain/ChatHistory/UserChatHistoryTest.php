<?php

declare(strict_types=1);

namespace Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserChatHistoryTest extends TestCase
{
    public function testRemoveLastExchangeReturnsLastUserMessageAndUpdatesBothHistories(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE chat_history (user_id TEXT NOT NULL, thread_id TEXT PRIMARY KEY, messages TEXT NOT NULL, display_messages TEXT NOT NULL DEFAULT '[]', display_messages_count INTEGER NOT NULL DEFAULT 0)"
        );

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnMap([
            [Auth::USERID, 'user-1'],
            ['chatId', 'thread-1'],
        ]);

        $history = new UserChatHistory($session, $pdo);

        $displayMessages = [
            new UserMessage('Bonjour'),
            new AssistantMessage('Salut'),
            new UserMessage('Peux-tu refaire'),
            new AssistantMessage('Voici une autre version'),
        ];

        $llmMessages = [
            new UserMessage('[OC]Summary[/OC]'),
            new AssistantMessage('Summary ack'),
            new UserMessage('Peux-tu refaire'),
            new AssistantMessage('Voici une autre version'),
        ];

        $history->replaceDisplayMessages($displayMessages);
        $history->replaceMessages($llmMessages);

        $removedContent = $history->removeLastExchange();

        self::assertSame('Peux-tu refaire', $removedContent);
        self::assertCount(2, $history->getDisplayMessages());
        self::assertCount(2, $history->getMessages());
        self::assertSame('Bonjour', $history->getDisplayMessages()[0]->getContent());
        self::assertSame('Salut', $history->getDisplayMessages()[1]->getContent());
        self::assertSame('[OC]Summary[/OC]', $history->getMessages()[0]->getContent());
        self::assertSame('Summary ack', $history->getMessages()[1]->getContent());
    }
}
