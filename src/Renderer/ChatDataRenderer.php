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
    public function messages(?array $messages, string $userId): array
    {
        return array_map(fn (array $message): array => $this->message($message, $userId), $messages ?? []);
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
