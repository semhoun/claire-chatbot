<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\ChatHistory\ReadOnlyChatHistory;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use Odan\Session\SessionInterface;

/**
 *
 */
class Summary extends Agent
{
    public function __construct(
        protected Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
    ) {
        parent::__construct();
    }

    /**
     * Generate summary JSON for the current conversation context.
     * Always returns an array with keys 'title' and 'summary'.
     * This is intended for internal use only (no controller/route).
     *
     * @return array{title:string, summary:string}
     */
    public function generate(): array
    {
        $userMessage = new UserMessage(
            "Analyse toute la conversation et réponds strictement en JSON avec les clés 'title' et 'summary'."
        );

        $message = $this->chat($userMessage);
        $content = trim($message->getContent());

        $title = 'Nouvelle conversation';
        $summary = '';

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $t = (string) ($decoded['title'] ?? '');
            $s = (string) ($decoded['summary'] ?? '');
            if ($t !== '') {
                $title = $t;
            }

            if ($s !== '') {
                $summary = $s;
            }
        } catch (\JsonException) {
            // keep defaults
        }

        return [
            'title' => $title,
            'summary' => $summary,
        ];
    }

    /**
     * Persist title/summary into chat_history for current session thread and user.
     * No-op if session is missing identifiers.
     *
     * @param array{title?:string|null, summary?:string|null} $data
     */
    public function persist(array $data): void
    {
        $userId = (string) ($this->session->get('userId') ?? '');
        $threadId = (string) ($this->session->get('chatId') ?? '');
        if ($userId === '' || $threadId === '') {
            return;
        }

        $fields = [];
        if (array_key_exists('title', $data)) {
            $fields['title'] = $data['title'];
        }

        if (array_key_exists('summary', $data)) {
            $fields['summary'] = $data['summary'];
        }

        if ($fields === []) {
            return;
        }

        $this->connection->update('chat_history', $fields, [
            'user_id' => $userId,
            'thread_id' => $threadId,
        ]);
    }

    /**
     * Generates data, persists it, and returns the generated data.
     *
     * @return array The generated data after being persisted.
     */
    public function generateAndPersist(): array
    {
        $result = $this->generate();
        $this->persist($result);
        return $result;
    }

    #[\Override]
    protected function chatHistory(): ChatHistoryInterface
    {
        return new ReadOnlyChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            table: 'chat_history'
        );
    }

    #[\Override]
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [$this->settings->get('llm.summary.systemPrompt')],
        );
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAILike(
            baseUri: $this->settings->get('llm.openai.baseUri'),
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.model')
        );
    }
}
