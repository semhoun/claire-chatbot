<?php

declare(strict_types=1);

namespace App\Brain;

use NeuronAI\Chat\Messages\UserMessage;

class Agent extends \NeuronAI\Agent\Agent
{
    use AgentTrait\AIProvider;
    use AgentTrait\UserChatHistory;
    use AgentTrait\Middleware;
    use AgentTrait\Constructor;

    public function getOpeningText(): string
    {
        // Générer le message de bienvenue via le LLM
        $userMessage = new UserMessage(
            <<<'PROMPT'
[OC]Génère un message de bienvenue chaleureux et concis pour accueillir l'utilisateur. Présente-toi brièvement et invite l'utilisateur à poser ses questions. Réponds uniquement avec le message de bienvenue, sans guillemets ni formatage, n'utilise pas d'outil pour ce message.[/OC]
PROMPT
        );
        $userMessage->addMetadata('message_type', 'out_of_context');

        try {
            $agentMessage = $this->chat($userMessage)->getMessage();
            return $agentMessage->getContent();
        } catch (\Throwable) {
            // En cas d'erreur, retourner un message par défaut
            return "Bonjour ! Comment puis-je vous aider aujourd'hui ?";
        }
    }

    #[\Override]
    public function resolveInstructions(): string
    {
        $instructions = parent::resolveInstructions();

        if (! str_contains($instructions, '[OC] Date et heure actuelles')) {
            $dateLine = sprintf(
                '[OC] Date et heure actuelles : %s[/OC]',
                new \DateTimeImmutable()->format('Y-m-d H:i:s')
            );
            $instructions =
                '[OC]' . "\n"
                . 'Tout ce qui est encadré par [OC] et [/OC] est une instruction système ou une métadonnée hors contexte.'. "\n"
                . 'IMPORTANT: Ne jamais inventer ou halluciner d\'identifiants de fichiers ou d\'images (format @@GENERATED@@...@@). N\'utilise que des identifiants qui t\'ont été explicitement fournis par un outil (ex: generate_image, generate_pdf) au cours de cette conversation. N\'invente jamais d\'identifiants fictifs comme @@GENERATED@@placeholder@@.' . "\n"
                . 'Si tu dois utiliser un fichier ou une image dans un autre outil (ex: mettre une image dans un PDF), tu DOIS d\'abord appeler l\'outil de génération, attendre de recevoir l\'identifiant réel, puis appeler le second outil. Ne fais JAMAIS d\'appels d\'outils en parallèle si l\'un dépend de l\'identifiant généré par l\'autre.' . "\n"
                . '[/OC]' . "\n"
                . "\n"
                . $instructions
                . "\n"
                . $dateLine
                . "\n";
        }

        $nickname = $this->session->get('user_info')['displayName'] ?? null;
        return str_replace('{{USER}}', $nickname, $instructions);
    }
}
