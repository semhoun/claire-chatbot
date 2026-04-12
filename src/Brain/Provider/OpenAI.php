<?php

declare(strict_types=1);

namespace App\Brain\Provider;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAILike;

class OpenAI extends OpenAILike
{
    /**
     * @param array<int, string> $rawMimeTypes
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $baseUri,
        protected string $key,
        protected string $model,
        protected array $rawMimeTypes = [],
        protected array $parameters = [],
        protected bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($baseUri, $key, $model, $parameters, $strict_response, $httpClient);
    }

    #[\Override]
    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper($this->rawMimeTypes);
    }
}
