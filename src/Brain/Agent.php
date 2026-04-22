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

        if (!str_contains($instructions, '[OC] Date et heure actuelles')) {
            $dateLine = sprintf(
                "[OC] Date et heure actuelles : %s[/OC]",
                new \DateTimeImmutable()->format('Y-m-d H:i:s')
            );
            $instructions =
                '[OC]Tout ce qui est encadré par [OC] et [/OC] est une instruction système ou une métadonnée hors contexte.[OC]'
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
