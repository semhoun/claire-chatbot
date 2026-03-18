<?php

declare(strict_types=1);

namespace App\Brain;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;

class Claire extends Agent implements BrainAvatar
{
    use ClaireAvatar;
    use AgentTrait\Tools;

    public const string NAME = 'Claire';

    public const string DESCRIPTION = 'Claire votre assistante personnelle, prête à vous accompagner dans vos tâches quotidiennes.';

    public const string CSS = 'claire.css';

    public const string WELCOME_SEPARATOR = '|||';

    public function getOpeningText(): string
    {
        $customMessages = env('CLAIRE_WELCOME_MESSAGES');

        if ($customMessages !== null) {
            $messages = explode(self::WELCOME_SEPARATOR, $customMessages);
            $messages = array_map(trim(...), $messages);
            $messages = array_filter($messages);

            if ($messages !== []) {
                return $messages[array_rand($messages)];
            }
        }

        $singleMessage = env('CLAIRE_WELCOME_MESSAGE');

        if ($singleMessage !== null) {
            return $singleMessage;
        }

        return "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";
    }

    #[\Override]
    protected function instructions(): string
    {
        $customPrompt = env('CLAIRE_PROMPT');

        if ($customPrompt !== null) {
            return $customPrompt;
        }

        return (string) new SystemPrompt(
            background: [
                'Tu es Claire mon assistant personnel.',
                'Ton rôle est de m\'aider à organiser mes idées, planifier mes tâches, et répondre rapidement à mes demandes.',
                'Tu dois être clair, synthétique, proactif.',
            ],
            steps: [],
            output: [
                'Pose-moi toujours une question à la fin pour m\'aider à avancer.',
            ]
        );
    }
}
