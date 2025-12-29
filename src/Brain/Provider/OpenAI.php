<?php

declare(strict_types=1);

namespace App\Brain\Provider;

use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAILike;

class OpenAI extends OpenAILike
{
    #[\Override]
    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }
}
