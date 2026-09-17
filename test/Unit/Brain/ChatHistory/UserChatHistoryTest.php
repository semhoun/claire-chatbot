<?php

declare(strict_types=1);

namespace Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class UserChatHistoryTest extends TestCase
{
    public function testAssistantIdentitySurvivesRoundTripAndToolGroupingWithoutChangingLlmContext(): void
    {
        [$history, $pdo, $session] = $this->history();
        $tool = new Tool('lookup')->setCallId('call-1')->setResult('Tool result');
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new ToolCallMessage(null, [$tool]));
        $history->addMessage(new ToolResultMessage([$tool]));
        $history->addMessage(new AssistantMessage('Final answer'));
        $llmBefore = $pdo->query('SELECT messages FROM chat_history')->fetchColumn();
        $history->identifyLastAssistantMessage('assistant-message-roundtrip', 'auto-assistant-message-roundtrip');
        self::assertSame($llmBefore, $pdo->query('SELECT messages FROM chat_history')->fetchColumn());
        $fresh = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        self::assertSame('assistant-message-roundtrip', $fresh->getDisplayMessages()[3]
            ->getMetadata(UserChatHistory::MESSAGE_ID_METADATA));
        self::assertSame('assistant-message-roundtrip', $fresh->getFormattedMessages()[1]['id']);
        self::assertSame('auto-assistant-message-roundtrip', $fresh->getFormattedMessages()[1]['audioRequestId']);
        self::assertSame('Tool result', $fresh->getFormattedMessages()[1]['toolsCall'][0]['result']);
        $fresh->addMessage(new UserMessage('Next question'));
        $fresh->addMessage(new AssistantMessage('Next answer'));
        $fresh->identifyLastAssistantMessage('assistant-message-next');
        $reloaded = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        self::assertSame(['history-message-0', 'assistant-message-roundtrip', 'history-message-2',
            'assistant-message-next'], array_column($reloaded->getFormattedMessages(), 'id'));
        self::assertArrayNotHasKey('audioRequestId', $reloaded->getFormattedMessages()[3]);
    }

    public function testAudioIdentityCanBeClearedWithoutChangingLlmContext(): void
    {
        [$history, $pdo, $session] = $this->history();
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new AssistantMessage('Answer'));
        $llmBefore = $pdo->query('SELECT messages FROM chat_history')->fetchColumn();
        $history->identifyLastAssistantMessage('assistant-answer', 'auto-assistant-answer');
        $history->identifyLastAssistantMessage('assistant-answer');
        $fresh = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        self::assertArrayNotHasKey('audioRequestId', $fresh->getFormattedMessages()[1]);
        self::assertSame($llmBefore, $pdo->query('SELECT messages FROM chat_history')->fetchColumn());
    }

    public function testInvalidAudioIdentityDoesNotChangePersistedHistory(): void
    {
        [$history, $pdo] = $this->history();
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new AssistantMessage('Answer'));
        $before = $pdo->query('SELECT display_messages FROM chat_history')->fetchColumn();
        foreach (['', 'request with spaces', "valid\n", str_repeat('a', 129)] as $invalid) {
            try {
                $history->identifyLastAssistantMessage('assistant-answer', $invalid);
                self::fail('Invalid audio identity accepted');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Invalid audio request ID', $exception->getMessage());
            }
            self::assertSame($before, $pdo->query('SELECT display_messages FROM chat_history')->fetchColumn());
        }
    }

    public function testAssistantIdentityWriteRejectsStaleHistory(): void
    {
        [$history, $pdo, $session] = $this->history();
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new AssistantMessage('Answer'));
        $stale = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        $history->removeLastExchange();
        $this->expectExceptionMessage('refusing stale snapshot');
        $stale->identifyLastAssistantMessage('assistant-message-late');
    }

    public function testToolCallCannotBeIdentifiedAsTheFinalAssistant(): void
    {
        [$history] = $this->history();
        $history->addMessage(new UserMessage('Question'));
        $history->addMessage(new ToolCallMessage(null, []));
        $this->expectExceptionMessage('Final assistant message is missing');
        $history->identifyLastAssistantMessage('assistant-message-late');
    }

    public function testStaleSnapshotCannotOverwriteAnotherWriter(): void
    {
        [$first, $pdo, $session] = $this->history();
        $stale = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        $first->addMessage(new UserMessage('Concurrent message'));
        try {
            $stale->replaceMessages([new UserMessage('Stale replacement')]);
            self::fail('Stale writer was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refusing stale snapshot', $exception->getMessage());
        }
        $fresh = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        self::assertSame('Concurrent message', $fresh->getMessages()[0]->getContent());
    }

    public function testStaleWriterCannotResurrectDeletedExchange(): void
    {
        [$history, $pdo, $session] = $this->history();
        $history->addMessage(new UserMessage('Remove me'));
        $stale = new UserChatHistory($session, $pdo, threadId: 'thread-1');
        $history->removeLastExchange();
        $this->expectException(\RuntimeException::class);
        $stale->addMessage(new AssistantMessage('Late answer'));
    }

    public function testDeletedThreadIsNotRecreatedByReadOrStaleWrite(): void
    {
        [$history, $pdo, $session] = $this->history();
        $pdo->exec('DELETE FROM chat_history');
        $read = new UserChatHistory($session, $pdo, threadId: 'thread-1', createIfMissing: false);
        self::assertSame([], $read->getFormattedMessages());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM chat_history')->fetchColumn());
        $this->expectException(\RuntimeException::class);
        $history->addMessage(new UserMessage('Late message'));
    }

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
    revision INTEGER NOT NULL DEFAULT 0,
    current_turn_id TEXT DEFAULT NULL,
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
    revision INTEGER NOT NULL DEFAULT 0,
    current_turn_id TEXT DEFAULT NULL,
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
    revision INTEGER NOT NULL DEFAULT 0,
    current_turn_id TEXT DEFAULT NULL,
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
