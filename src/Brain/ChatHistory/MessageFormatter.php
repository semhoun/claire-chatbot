<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;

final class MessageFormatter
{
    public function __construct(private ?array $displayHistory)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function format(): array
    {
        $data = [];
        $messageIndex = 0;

        while (count($this->displayHistory) > 0) {
            $message = array_shift($this->displayHistory);
            $formattedMessage = $this->formatMessage($message);
            if ($formattedMessage === null) {
                continue;
            }

            $formattedMessage['id'] ??= sprintf('history-message-%d', $messageIndex);
            $data[] = $formattedMessage;
            $messageIndex++;
        }

        return $data;
    }

    /**
     * Si c'est un message Tool, on va dépiler jusqy'a trouver une message non tool.
     *
     * @return array<string, mixed>|null
     */
    private function formatMessage(mixed $message, ?array $formattedMessage = null): ?array
    {
        $formattedMessage ??= [
            'message' => '',
            'time' => $message->getMetadata('timestamp') ?? '',
            'sent' => $message->getRole() === 'user',
            'toolRunning' => false,
            'toolsCall' => [],
            'running' => false,
        ];

        if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
            $tools = $this->formatTools($message);
            if ($tools !== []) {
                $formattedMessage['toolsCall'] = array_merge($formattedMessage['toolsCall'], $tools);
            }

            // An interrupted tool group must not consume the next user turn.
            if (count($this->displayHistory) === 0
                || ($this->displayHistory[0] instanceof UserMessage
                    && ! $this->displayHistory[0] instanceof ToolResultMessage)) {
                return $formattedMessage;
            }

            $message = array_shift($this->displayHistory);
            return $this->formatMessage($message, $formattedMessage);
        }

        $formattedMessage['message'] = $message->getContent();
        $messageId = $message->getMetadata(UserChatHistory::MESSAGE_ID_METADATA);
        if ($message instanceof AssistantMessage && is_string($messageId)
            && preg_match(UserChatHistory::MESSAGE_ID_PATTERN, $messageId) === 1) {
            // Tool groups inherit the identity of their final assistant, not the tool call.
            $formattedMessage['id'] = $messageId;
            $audioRequestId = $message->getMetadata(UserChatHistory::AUDIO_REQUEST_ID_METADATA);
            if (is_string($audioRequestId)
                && preg_match(UserChatHistory::AUDIO_REQUEST_ID_PATTERN, $audioRequestId) === 1) {
                $formattedMessage['audioRequestId'] = $audioRequestId;
            }
        }

        return $formattedMessage;
    }

    /** @return array<int, array<string, mixed>> */
    private function formatTools(ToolCallMessage | ToolResultMessage $message): array
    {
        $tools = [];
        if ($message instanceof ToolCallMessage) {
            foreach ($message->getTools() as $tool) {
                $callId = $tool->getCallId();

                // On regarde si dans les réponse on a une réponse avec cette id, si c'est le cas on ne le décodera pas
                foreach ($this->displayHistory as $toolResult) {
                    if (! $toolResult instanceof ToolResultMessage) {
                        continue;
                    }

                    foreach ($toolResult->getTools() as $toolRes) {
                        if ($toolRes->getCallId() === $callId) {
                            continue 3;
                        }
                    }
                }

                // On a pas trouvé de réponse donc c'est un tool encore en cours d'éxécution
                $tools[] = $this->formatTool($tool, false);
            }

            return $tools;
        }

        foreach ($message->getTools() as $tool) {
            $tools[] = $this->formatTool($tool, $message instanceof ToolResultMessage);
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function formatTool(ToolInterface $tool, bool $isResult): array
    {
        $toolData = [
            'id' => $tool->getCallId(),
            'name' => $tool->getName(),
            'inputs' => [],
            'running' => ! $isResult,
            'result' => null,
        ];

        foreach ($tool->getInputs() as $name => $val) {
            $toolData['inputs'][] = [
                'name' => $name,
                'value' => $val,
            ];
        }

        if ($isResult) {
            $toolData['result'] = $tool->getResult();
        }

        return $toolData;
    }
}
