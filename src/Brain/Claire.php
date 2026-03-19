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

    public const string WELCOME_SEPARATOR = '|||';

    public function getOpeningText(): string
    {
        $welcomeMessages = [
            'Je suis Claire, votre assistant virtuel. Je suis là pour répondre à vos questions, vous guider ou résoudre vos problèmes 24h/24.',
            'Bonjour ! Comment puis-je t\'aider aujourd\'hui ?',
            'Moi c’est Claire, ton allié pour tout ce qui te tracasse (ou presque). Un souci ? Une question ? Je suis là pour t’aider en 2 clics.',
             'Bonjour et bienvenue ! Comment puis-je t\'aider aujourd\'hui ?',
        ];

        return $welcomeMessages[array_rand($welcomeMessages)];
    }

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
