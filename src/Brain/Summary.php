<?php

declare(strict_types=1);

namespace App\Brain;

use App\Brain\ChatHistory\SummaryChatHistory;
use App\Brain\ChatHistory\UserChatHistory;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

/**
 * Agent responsible for generating concise titles and summaries for chat conversations
 * using an AI provider and persisting them to the database.
 */
class Summary extends \NeuronAI\Agent\Agent
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
    ) {
        parent::__construct();

        $this->observe(new \App\Brain\Observability\Observer());
    }

    /**
     * Generates a title and summary based on the processed content.
     *
     * @return array An associative array containing the following keys:
     *               - 'title': The generated title or a default value if generation fails.
     *               - 'summary': The generated summary or an empty string if generation fails.
     */
    public function generate(): array
    {
        $userMessage = new UserMessage(
            "Génère 'title' et 'summary'."
        );

        $message = $this->chat($userMessage)->getMessage();
        $content = $message->getContent();

        // On isole la partie JSON entre le premier "{" et le dernier "}"
        $startPos = strpos((string) $content, '{');
        $endPos = strrpos((string) $content, '}');
        if ($startPos !== false && $endPos !== false && $endPos >= $startPos) {
            $content = substr((string) $content, $startPos, $endPos - $startPos + 1);
        }

        $title = 'Nouvelle conversation';
        $summary = '';

        try {
            $decoded = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
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
        $userId = (string) ($this->session->get(Auth::USERID) ?? '');
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

        $this->connection->update(UserChatHistory::TABLE, $fields, [
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
        return new SummaryChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection()
        );
    }

    #[\Override]
    protected function instructions(): string
    {
        return <<<EOF
Tu es un assistant qui génère un titre (title) concis et un résumé (summary) bref pour une conversation.
Règles:
  1) Réponds exclusivement avec un JSON avec les clés "title" et "summary".
  2) Le titre "title" en français, clair, <= 80 caractères, sans guillemets décoratifs.
  3) Le résumé "summary" en français, 1 à 3 phrases, <= 400 caractères, pas de balises Markdown.
  4 ) Si le contenu est vide, mets title="Nouvelle conversation" et summary="".
EOF;
    }

    #[\Override]
    protected function provider(): AIProviderInterface
    {
        return new \App\Brain\Provider\OpenAI(
            baseUri: $this->settings->get('llm.openai.baseUri'),
            key: $this->settings->get('llm.openai.key'),
            model: $this->settings->get('llm.openai.modelSummary'),
            rawMimeTypes: $this->settings->get('llm.rawMimeTypes'),
        );
    }
}
