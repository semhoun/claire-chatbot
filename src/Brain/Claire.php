<?php

declare(strict_types=1);

namespace App\Brain;

use NeuronAI\Agent\SystemPrompt;

class Claire extends Agent implements BrainAvatar
{
    use ClaireAvatar;
    use AgentTrait\Tools;

    public const string NAME = 'Claire';

    public const string DESCRIPTION = 'Claire votre assistante personnelle, prête à vous accompagner dans vos tâches quotidiennes.';

    public const string CSS = 'claire.css';

    #[\Override]
    protected function instructions(): string
    {
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
