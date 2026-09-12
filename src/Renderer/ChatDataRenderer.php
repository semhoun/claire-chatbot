<?php

declare(strict_types=1);

namespace App\Renderer;

use App\Services\Rendering\GeneratedFileProcessor;

final readonly class ChatDataRenderer
{
    public function __construct(private GeneratedFileProcessor $generatedFileProcessor)
    {
    }

    /** @return array<string, mixed> */
    public function content(string $message, string $userId, bool $pending = false): array
    {
        return [
            'message' => $message,
            'files' => $this->generatedFileProcessor->resolve($message, $userId, $pending),
        ];
    }

    /** @param array<int, array<string, mixed>>|null $messages
     * @return array<int, array<string, mixed>>
     */
    public function messages(?array $messages, string $userId, bool $running = false): array
    {
        $messages = array_values($messages ?? []);
        foreach ($messages as $index => &$message) {
            $message = $this->message($message, $userId);
            foreach ($message['toolsCall'] as &$tool) {
                if (($tool['running'] ?? false) && (! $running || $index !== array_key_last($messages)
                    || $message['sent'])) {
                    $tool['running'] = false;
                    $tool['interrupted'] = true;
                }
            }
            unset($tool);
        }
        unset($message);
        return $messages;
    }

    /** @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function message(array $message, string $userId): array
    {
        return [
            'id' => (string) ($message['id'] ?? ''),
            'time' => (string) ($message['time'] ?? ''),
            'sent' => ($message['sent'] ?? false) === true,
            'toolsCall' => array_values($message['toolsCall'] ?? []),
            ...$this->content((string) ($message['message'] ?? ''), $userId),
        ];
    }
}
