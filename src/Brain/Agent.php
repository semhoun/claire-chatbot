<?php

declare(strict_types=1);

namespace App\Brain;

class Agent extends \NeuronAI\Agent\Agent
{
    use AgentTrait\AIProvider;
    use AgentTrait\UserChatHistory;
    use AgentTrait\Middleware;
    use AgentTrait\Constructor;
}
