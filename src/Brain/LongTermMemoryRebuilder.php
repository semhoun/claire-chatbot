<?php

declare(strict_types=1);

namespace App\Brain;

use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use RuntimeException;

final class LongTermMemoryRebuilder extends \NeuronAI\Agent\Agent
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Settings $settings,
        private readonly SessionInterface $session,
    ) {
        parent::__construct();
        $this->observe(new \App\Brain\Observability\Observer());
    }

    public function rebuild(): string
    {
        $userId = (string) $this->session->get(Auth::USERID, '');
        if ($userId === '') {
            throw new RuntimeException('Cannot rebuild memory without a user');
        }

        $summaries = $this->connection->fetchFirstColumn(
            "SELECT summary FROM chat_history WHERE user_id = :user_id "
            . "AND summary IS NOT NULL AND summary <> '' ORDER BY id ASC",
            ['user_id' => $userId]
        );
        $memory = '';
        $batchSize = $this->settings->get('llm.longTermMemory.rebuildBatchSize');

        foreach (array_chunk($summaries, $batchSize) as $batch) {
            $agent = new self($this->connection, $this->settings, $this->session);
            $memory = $agent->consolidate($memory, $batch);
        }

        $longTermMemory = new LongTermMemory(
            connection: $this->connection,
            session: $this->session,
            maxCharacters: $this->settings->get('llm.longTermMemory.maxCharacters'),
        );
        $longTermMemory->replace($memory);

        return $memory;
    }

    /** @param array<int, string> $summaries */
    private function consolidate(string $memory, array $summaries): string
    {
        $prompt = "Mémoire consolidée jusqu'ici :\n"
            . ($memory === '' ? '(vide)' : $memory)
            . "\n\nNouveaux résumés historiques à intégrer :\n- "
            . implode("\n- ", $summaries);
        $content = $this->chat(new UserMessage($prompt))->getMessage()->getContent();
        $result = $this->extractMemory($content);

        if ($result === '') {
            throw new RuntimeException('The model returned an empty long-term memory');
        }

        return mb_substr(
            $result,
            0,
            $this->settings->get('llm.longTermMemory.maxCharacters')
        );
    }

    #[\Override]
    protected function instructions(): string
    {
        $maxCharacters = $this->settings->get('llm.longTermMemory.maxCharacters');

        return <<<PROMPT
Reconstruis une mémoire utilisateur évolutive depuis des résumés de conversations.
Fusionne les préférences, objectifs, contraintes, décisions et faits personnels durables.
Résous les contradictions en privilégiant les informations les plus récentes.
N'inclus ni secrets, ni mots de passe, ni jetons, ni données bancaires, ni faits temporaires.
N'écris pas un journal chronologique. La mémoire doit faire au maximum {$maxCharacters} caractères.
Réponds exclusivement avec un objet JSON contenant la clé "memory".
PROMPT;
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

    private function extractMemory(?string $content): string
    {
        if ($content === null) {
            return '';
        }

        try {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            $json = $start !== false && $end !== false
                ? substr($content, $start, $end - $start + 1)
                : $content;
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return trim((string) ($decoded['memory'] ?? ''));
        } catch (\JsonException) {
            return '';
        }
    }
}
