<?php

declare(strict_types=1);

namespace App\Brain;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;

class Claire extends Agent implements BrainAvatar
{
    use ClaireAvatar;

    public const string NAME = 'Claire';

    public const string DESCRIPTION = 'Claire votre assistante personnelle, prête à vous accompagner dans vos tâches quotidiennes.';

    public const string CSS = 'claire.css';

    public function getOpeningText(): string
    {
        return "Bonjour et bienvenue ! Comment puis-je t'aider aujourd'hui ?";
    }

    #[\Override]
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'Tu es Claire mon assistant personnel.',
                'Ton rôle est de m’aider à organiser mes idées, planifier mes tâches, et répondre rapidement à mes demandes.',
                'Tu dois être clair, synthétique, proactif.',
            ],
            steps: [],
            output: [
                'Pose-moi toujours une question à la fin pour m’aider à avancer.',
            ]
        );
    }

    #[\Override]
    protected function tools(): array
    {
        // TODO gérer les erreurs
        return [
            CalculatorToolkit::make(),
            CalendarToolkit::make(),
            Tools\WebToolkit::make($this->settings->get('llm.tools.searchXngUrl')),
        ];
    }
}
