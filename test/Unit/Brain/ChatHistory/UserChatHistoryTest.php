<?php

declare(strict_types=1);

namespace Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class UserChatHistoryTest extends TestCase
{
    public function testInitializeWithOpeningMessageAcceptsFirstRealUserMessage(): void
    {
        [$history] = $this->history();
        $assistantMessage = new AssistantMessage('Bienvenue !');

        $history->initializeWithOpeningMessage($assistantMessage);
        $history->addMessage(new UserMessage('Que peux-tu faire ?'));

        self::assertCount(3, $history->getMessages());
        self::assertInstanceOf(UserMessage::class, $history->getMessages()[0]);
        self::assertSame($assistantMessage, $history->getMessages()[1]);
        self::assertCount(2, $history->getDisplayMessages());
    }

    public function testLoadRepairsHistoryStartingWithOpeningAssistantMessage(): void
    {
        [, $pdo, $session] = $this->history();
        $assistantMessage = new AssistantMessage('Bienvenue !');
        $statement = $pdo->prepare(
            <<<'SQL'
UPDATE chat_history
SET messages = :messages
WHERE thread_id = :thread_id
SQL
        );
        $statement->execute([
            'messages' => json_encode([$assistantMessage], JSON_THROW_ON_ERROR),
            'thread_id' => 'thread-1',
        ]);

        $userChatHistory = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        $userChatHistory->addMessage(new UserMessage('Bonjour'));

        self::assertCount(3, $userChatHistory->getMessages());
        self::assertInstanceOf(UserMessage::class, $userChatHistory->getMessages()[0]);
        self::assertSame(
            $assistantMessage->getContent(),
            $userChatHistory->getMessages()[1]->getContent(),
        );
    }

    public function testInitializeWithOpeningMessageCanKeepDisplayHistoryEmpty(): void
    {
        [$history] = $this->history();
        $assistantMessage = new AssistantMessage('Bienvenue sur Telegram !');

        $history->initializeWithOpeningMessage($assistantMessage, false);

        self::assertSame($assistantMessage, $history->getMessages()[1]);
        self::assertSame([], $history->getDisplayMessages());
    }

    public function testRemoveLastExchangeReturnsLastUserMessageAndUpdatesBothHistories(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
CREATE TABLE chat_history (
    user_id TEXT NOT NULL,
    thread_id TEXT PRIMARY KEY,
    messages TEXT NOT NULL,
    display_messages TEXT NOT NULL DEFAULT '[]',
    display_messages_count INTEGER NOT NULL DEFAULT 0,
    title TEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL
)
SQL
        );

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnMap([
            [Auth::USERID, 'user-1'],
            ['threadId', 'thread-1'],
        ]);

        $userChatHistory = new UserChatHistory($session, $pdo);

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

        $userChatHistory->replaceDisplayMessages($displayMessages);
        $userChatHistory->replaceMessages($llmMessages);

        $removedContent = $userChatHistory->removeLastExchange();

        self::assertSame('Peux-tu refaire', $removedContent);
        self::assertCount(2, $userChatHistory->getDisplayMessages());
        self::assertCount(2, $userChatHistory->getMessages());
        self::assertSame('Bonjour', $userChatHistory->getDisplayMessages()[0]->getContent());
        self::assertSame('Salut', $userChatHistory->getDisplayMessages()[1]->getContent());
        self::assertSame('[OC]Summary[/OC]', $userChatHistory->getMessages()[0]->getContent());
        self::assertSame('Summary ack', $userChatHistory->getMessages()[1]->getContent());
    }

    public function testLoadExposesTitleAndSummary(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
CREATE TABLE chat_history (
    user_id TEXT NOT NULL,
    thread_id TEXT PRIMARY KEY,
    messages TEXT NOT NULL,
    display_messages TEXT NOT NULL DEFAULT '[]',
    display_messages_count INTEGER NOT NULL DEFAULT 0,
    title TEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL
)
SQL
        );
        $statement = $pdo->prepare(
            'INSERT INTO chat_history (user_id, thread_id, messages, display_messages, display_messages_count, title, summary) VALUES (:user_id, :thread_id, :messages, :display_messages, :display_messages_count, :title, :summary)'
        );
        $statement->execute([
            'user_id' => 'user-1',
            'thread_id' => 'thread-1',
            'messages' => '[]',
            'display_messages' => '[]',
            'display_messages_count' => 0,
            'title' => 'Titre de test',
            'summary' => 'Resume de test',
        ]);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnMap([
            [Auth::USERID, 'user-1'],
            ['threadId', 'thread-1'],
        ]);

        $userChatHistory = new UserChatHistory($session, $pdo, 50000, 'thread-1');

        self::assertSame('Titre de test', $userChatHistory->getTitle());
        self::assertSame('Resume de test', $userChatHistory->getSummary());
    }

    /** @return array{UserChatHistory,PDO,SessionInterface} */
    private function history(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
CREATE TABLE chat_history (
    user_id TEXT NOT NULL,
    thread_id TEXT PRIMARY KEY,
    messages TEXT NOT NULL,
    display_messages TEXT NOT NULL DEFAULT '[]',
    display_messages_count INTEGER NOT NULL DEFAULT 0,
    title TEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL
)
SQL
        );

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnMap([
            [Auth::USERID, 'user-1'],
            ['threadId', 'thread-1'],
        ]);

        return [new UserChatHistory($session, $pdo, threadId: 'thread-1'), $pdo, $session];
    }
}
