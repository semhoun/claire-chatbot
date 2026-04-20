<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
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

        while (count($this->displayHistory) > 0) {
            $message = array_shift($this->displayHistory);
            $data[] = $this->formatMessage($message);
        }

        return $data;
    }

    /**
     * Si c'est un message Tool, on va dépiler jusqy'a trouver une message non tool
     *
     * @return array<string, mixed>|null
     */
    private function formatMessage(mixed $message, ?array $formattedMessage = null): ?array
    {
        if ($formattedMessage === null) {
            $formattedMessage = [
                'message' => '',
                'time' => $message->getMetadata('timestamp') ?? '',
                'sent' => $message->getRole() === 'user',
                'toolRunning' => false,
                'toolsCall' => [],
                'running' => false
            ];
        }

        if ($message->getContent() !== null) {
            $formattedMessage['message'] .= $message->getContent();
        }

        if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
            $tools = $this->formatTools($message);
            if (count($tools) > 0) {
                $formattedMessage['toolsCall'] = array_merge($formattedMessage['toolsCall'], $tools);
            }
            if (count($this->displayHistory) === 0) {
                return $formattedMessage;
            }
            $message = array_shift($this->displayHistory);
            return $this->formatMessage($message, $formattedMessage);
        }

        return $formattedMessage;
    }

    private function formatTools(ToolCallMessage | ToolResultMessage $message): array
    {
        $tools = [];
        if ($message instanceof ToolCallMessage) {
            foreach ($message->getTools() as $tool) {
                $callId = $tool->getCallId();

                // On regarde si dans les réponse on a une réponse avec cette id, si c'est le cas on ne le décodera pas
                foreach ($this->displayHistory as $toolResult) {
                    if (!$toolResult instanceof ToolResultMessage) {
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

    private function formatTool(ToolInterface $tool, bool $isResult): array
    {
        $toolData = [
            'id' => $tool->getCallId(),
            'name' => $tool->getName(),
            'inputs' => [],
            'running' => !$isResult,
            'result' => null
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
