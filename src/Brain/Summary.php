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
        protected readonly ?string $threadId = null,
    ) {
        parent::__construct();

        $this->observe(new \App\Brain\Observability\Observer());
    }

    public function generateAndPersist(): void
    {
        $userMessage = new UserMessage("Génère 'title' et 'summary'.");
        $message = $this->chat($userMessage)->getMessage();
        $jsonContent = $this->extractJsonContent($message->getContent());

        $this->connection->update(UserChatHistory::TABLE, [
            'title' => $jsonContent['title'] ?? 'Nouvelle conversation',
            'summary' => $jsonContent['summary'] ?? null,
        ], [
            'user_id' => $this->session->get(Auth::USERID),
            'thread_id' => $this->threadId,
        ]);
    }

    #[\Override]
    protected function chatHistory(): ChatHistoryInterface
    {
        return new SummaryChatHistory(
            session: $this->session,
            pdo: $this->connection->getNativeConnection(),
            contextWindow: $this->settings->get('llm.openai.contextWindow'),
            threadId: $this->threadId,
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

    /**
     * Extracts JSON content from a given string and decodes it into an associative array.
     *
     * @param string|null $content The input string potentially containing JSON data.
     *                              If null, an empty array will be returned.
     *
     * @return array<string, mixed> The decoded JSON content as an associative array.
     *                              Returns an empty array if the string does not contain valid JSON.
     */
    private function extractJsonContent(?string $content): array
    {
        if ($content === null) {
            return [];
        }

        try {
            $startPos = strpos($content, '{');
            $endPos = strrpos($content, '}');

            if ($startPos !== false && $endPos !== false && $endPos >= $startPos) {
                return json_decode(substr($content, $startPos, $endPos - $startPos + 1), true, 512, JSON_THROW_ON_ERROR);
            }

            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }
}
