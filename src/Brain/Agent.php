<?php

declare(strict_types=1);

namespace App\Brain;

class Agent extends \NeuronAI\Agent\Agent
{
    use AgentTrait\AIProvider;
    use AgentTrait\UserChatHistory;
    use AgentTrait\Middleware;
    use AgentTrait\Constructor;
    use AgentTrait\Nodes;

    public function getOpeningText(): string
    {
        return '';
    }

    #[\Override]
    public function resolveInstructions(): string
    {
        $instructions = parent::resolveInstructions();
        $dateLine = sprintf(
            "\n\n[Contexte système] Date et heure actuelles : %s\n",
            new \DateTimeImmutable()->format('Y-m-d H:i:s')
        );

        return $instructions . $dateLine;
    }
}
