<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\ChatHistory;

use App\Brain\ChatHistory\MessageFormatter;
use App\Brain\ChatHistory\UserChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

final class MessageFormatterTest extends TestCase
{
    public function testToolGroupUsesFinalAssistantIdentityNotToolMetadata(): void
    {
        $firstTool = new Tool('first')->setCallId('call-1')->setResult('first result');
        $secondTool = new Tool('second')->setCallId('call-2')->setResult('second result');
        $messages = new MessageFormatter([
            new UserMessage('Question'),
            new ToolCallMessage(null, [$firstTool])->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, 'wrong-tool-id')
                ->addMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA, 'wrong-tool-audio'),
            new ToolResultMessage([$firstTool]),
            new ToolCallMessage(null, [$secondTool]),
            new ToolResultMessage([$secondTool]),
            new AssistantMessage('Answer')->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, 'assistant-message-123')
                ->addMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA, 'auto-assistant-message-123'),
        ])->format();
        self::assertCount(2, $messages);
        self::assertSame('history-message-0', $messages[0]['id']);
        self::assertSame('assistant-message-123', $messages[1]['id']);
        self::assertSame('auto-assistant-message-123', $messages[1]['audioRequestId']);
        self::assertSame('Answer', $messages[1]['message']);
        self::assertSame(['call-1', 'call-2'], array_column($messages[1]['toolsCall'], 'id'));
        self::assertSame(['first result', 'second result'], array_column($messages[1]['toolsCall'], 'result'));
    }

    public function testInvalidAudioMetadataAndUserAudioMetadataAreNotExposed(): void
    {
        foreach ([null, '', ['id'], 'request with spaces', "valid\n", str_repeat('a', 129)] as $invalid) {
            $messages = new MessageFormatter([
                new UserMessage('Question')->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, 'user-id')
                    ->addMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA, 'injected-user-audio'),
                new AssistantMessage('Answer')->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, 'assistant-answer')
                    ->addMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA, $invalid),
            ])->format();
            self::assertArrayNotHasKey('audioRequestId', $messages[0]);
            self::assertArrayNotHasKey('audioRequestId', $messages[1]);
        }
    }

    public function testInvalidOrNonAssistantMetadataKeepsLegacySequentialIds(): void
    {
        foreach ([null, '', ['id'], 'bad id', '" onclick="x', str_repeat('x', 129)] as $invalidId) {
            $messages = new MessageFormatter([
                new UserMessage('Question')->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, 'user-injected-id'),
                new AssistantMessage('Answer')->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, $invalidId),
                new ToolCallMessage(null, [])->addMetadata(UserChatHistory::MESSAGE_ID_METADATA, 'tool-injected-id'),
            ])->format();
            self::assertSame(['history-message-0', 'history-message-1', 'history-message-2'],
                array_column($messages, 'id'));
        }
    }

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
