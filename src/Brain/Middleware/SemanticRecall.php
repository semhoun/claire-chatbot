<?php

declare(strict_types=1);

namespace App\Brain\Middleware;

use Closure;
use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/** Segment-local outbound projection: recalled data must never enter canonical messages or workflow state. */
final class SemanticRecall implements AIProviderInterface
{
    private bool $attempted = false;
    private ?string $messageId = null;
    private string $context = '';

    /** @param Closure(string): string $recall */
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly Closure $recall,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getModel(): string
    {
        return $this->provider->getModel();
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->provider->systemPrompt($prompt);
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        $this->provider->setTools($tools);
        return $this;
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        $this->provider->setHttpClient($client);
        return $this;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        return $this->provider->chat(...$this->project($messages));
    }

    public function stream(Message ...$messages): Generator
    {
        return yield from $this->provider->stream(...$this->project($messages));
    }

    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        return $this->provider->structured(
            $this->project(is_array($messages) ? $messages : [$messages]),
            $class,
            $response_schema,
        );
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<Message>
     */
    private function project(array $messages): array
    {
        if (! $this->attempted) {
            $this->attempted = true;
            $last = end($messages);
            $query = $last instanceof Message
                ? ($last->getMetadata('semantic_query') ?? $last->getContent()) : null;
            if ($last instanceof UserMessage && ! $last instanceof ToolResultMessage
                && $last->getMetadata('message_type') !== 'out_of_context'
                && is_string($query) && trim($query) !== '') {
                $this->messageId = $last->getId();
                try {
                    $this->context = ($this->recall)($query);
                } catch (Throwable $throwable) {
                    $this->logger->warning('Semantic memory recall unavailable', ['class' => $throwable::class]);
                }
            }
        }

        if ($this->context !== '') {
            foreach ($messages as $index => $message) {
                if ($message->getId() === $this->messageId) {
                    $messages[$index] = clone $message;
                    $messages[$index]->addContent(new TextContent(
                        "\n[BEGIN UNTRUSTED RECALLED CONVERSATION DATA]\n"
                        . $this->context . "\n[END UNTRUSTED RECALLED CONVERSATION DATA]",
                    ));
                    break;
                }
            }
        }

        return $messages;
    }
}
