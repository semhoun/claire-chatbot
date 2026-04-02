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
            "[OC]Génère un message de bienvenue chaleureux et concis pour accueillir l'utilisateur. " .
            "Présente-toi brièvement et invite l'utilisateur à poser ses questions. " .
            'Réponds uniquement avec le message de bienvenue, sans guillemets ni formatage.[/OC]'
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
        $dateLine = sprintf(
            "\n\n[OC] Date et heure actuelles : %s[/OC]\n",
            new \DateTimeImmutable()->format('Y-m-d H:i:s')
        );

        return $instructions . $dateLine;
    }
}
