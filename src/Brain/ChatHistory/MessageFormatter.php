<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

final class MessageFormatter
{
    private ?string $toolCallId = null;

    private ?string $toolText = null;

    /**
     * @param array<int, mixed> $displayHistory
     *
     * @return array<int, array<string, mixed>>
     */
    public function format(array $displayHistory): array
    {
        $data = [];

        foreach ($displayHistory as $message) {
            $formatted = $this->formatMessage($message);
            if ($formatted !== null) {
                $data[] = $formatted;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatMessage(mixed $message): ?array
    {
        if ($message instanceof ToolCallMessage) {
            $this->toolCallId = uniqid('tool-', true);
            return null;
        }

        if ($message instanceof ToolResultMessage) {
            $this->toolText = $this->formatToolResult($message);
            return null;
        }

        return $this->formatRegularMessage($message);
    }

    private function formatToolResult(ToolResultMessage $toolResultMessage): string
    {
        $text = '<span class="tools-done-flag" style="display:none"></span>' . "\n";

        foreach ($toolResultMessage->getTools() as $tool) {
            $text .= $this->formatTool($tool);
        }

        return $text;
    }

    private function formatTool(mixed $tool): string
    {
        $text = "Utilisation de l'outil : " . $tool->getName() . "<br>\n";
        $text .= "Paramètres : <br>\n";
        $text .= "<ul>\n";

        foreach ($tool->getInputs() as $name => $input) {
            $text .= '<li>' . $name . ' : ' . $input . "</li>\n";
        }

        $text .= "</ul>\n";
        $text .= "Réponse : <br>\n";

        if ($tool->getResult() !== '' && $tool->getResult() !== '0') {
            $text .= '<pre class="toolcall__result">' . $tool->getResult() . "</pre>\n";
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRegularMessage(mixed $message): array
    {
        $data = [
            'message' => $message->getContent() ?? '',
            'time' => $message->getMetadata('timestamp') ?? '',
            'sent' => $message->getRole() === 'user',
            'toolCallId' => $this->toolCallId,
            'toolText' => $this->toolText,
        ];

        $this->resetToolState();

        return $data;
    }

    private function resetToolState(): void
    {
        $this->toolCallId = null;
        $this->toolText = null;
    }
}
