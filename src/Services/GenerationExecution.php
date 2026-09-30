<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Middleware\CooperativeStop;
use Generator;
use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Tools\ToolOutput;
use RuntimeException;
use Throwable;

/** No persistence or recovery: the caller commits the stopped transcript under its turn fence. */
final class GenerationExecution
{
    private bool $used = false;
    /** @var array<string, Message> */
    private array $messages = [];
    /** @var array<string, ToolResultMessage> */
    private array $results = [];
    private ?string $lastToolMessageId = null;
    /** @var array<string, array<string, array{callId:?string, name:string, status:string}>> */
    private array $partialCalls = [];

    public function __construct(private readonly GenerationStopToken $token)
    {
    }

    /**
     * User turns only, never welcome generations. Decorate the generation provider's
     * existing HTTP client with token->httpClient() before calling this method.
     * Clone a shared provider and configure only a generation-scoped agent copy;
     * a stopped client's latched token must never be reused for another turn.
     * $beforeResume preserves the caller's lock/ownership checks.
     *
     * @param Message|list<Message> $messages
     *
     * @return Generator<int, object, mixed, GenerationExecutionResult>
     */
    public function stream(Agent $agent, Message|array $messages, ?callable $beforeResume = null): Generator
    {
        if ($this->used) {
            throw new LogicException('A generation execution cannot be replayed.');
        }
        $this->used = true;
        $messages = is_array($messages) ? $messages : [$messages];
        if (! array_any($messages, static fn (Message $message): bool => $message instanceof UserMessage && ! $message instanceof ToolResultMessage)) {
            throw new InvalidArgumentException('Cooperative execution requires user input, not a welcome generation.');
        }
        foreach ($messages as $message) {
            $this->messages[$message->getId()] = $message;
        }

        $stream = null;
        $state = null;
        try {
            if ($beforeResume !== null) {
                $beforeResume();
            }
            $this->token->check();
            // Keep stop listeners out of the shared definition and subsequent turns.
            $agent = $agent->for($agent->getThreadId() ?? throw new LogicException('Bind the agent thread first.'));
            $gate = new CooperativeStop($this->token);
            $agent->addGlobalMiddleware($gate);
            $agent->subscribe(ToolCalling::class, $gate);
            $agent->subscribe(InferenceStop::class, $this->recordResponse(...));
            $stream = $agent->stream($messages);
            while ($stream->valid()) {
                $chunk = $stream->current();
                if ($chunk instanceof TextChunk && $chunk->messageId !== null) {
                    $id = $chunk->messageId;
                    $text = ($this->messages[$id] ?? null)?->getContent() ?? '';
                    $this->messages[$id] = (new AssistantMessage($text . $chunk->content))->setId($id);
                }
                if ($chunk instanceof ToolArgumentChunk) {
                    $this->partialCalls[$chunk->messageId ?? ''][$chunk->toolCallId ?? $chunk->toolName] = [
                        'callId' => $chunk->toolCallId,
                        'name' => $chunk->toolName,
                        'status' => 'cancelled',
                    ];
                }
                yield $chunk;
                if ($beforeResume !== null) {
                    $beforeResume();
                }
                $this->token->check();
                $stream->next();
            }
            $state = $stream->getReturn();
            if ($state->isInterrupted()) {
                throw new RuntimeException('Unexpected interrupted generation; automatic resume is forbidden.');
            }
            $this->token->check();
        } catch (GenerationStopped $stop) {
            if ($stop->token !== $this->token) {
                throw $stop;
            }
            // Unwind the suspended workflow, releasing its lease without executing
            // the next tool or recovering the failed workflow with another invocation.
            if ($stream !== null && $stream->valid()) {
                try {
                    $stream->throw($stop);
                } catch (GenerationStopped $unwound) {
                    if ($unwound->token !== $this->token) {
                        throw $unwound;
                    }
                }
            }
        } catch (ProviderException $error) {
            // v4 deliberately has no assistant response when cancellation precedes
            // all text. Do not misclassify other provider failures as user stops.
            if ($error->getMessage() !== 'The stream was stopped before the answer started.'
                || ! $this->token->isRequested()) {
                throw $error;
            }
        } catch (Throwable $error) {
            if ($stream !== null && $stream->valid()) {
                try {
                    $stream->throw($error);
                } catch (Throwable) {
                    // The original delivery/ownership failure is authoritative.
                }
            }
            throw $error;
        }

        return new GenerationExecutionResult($this->token->isRequested(), $state, $this->transcript());
    }

    private function recordResponse(InferenceStop $event): void
    {
        if ($event->message instanceof ToolResultMessage && $this->lastToolMessageId !== null) {
            $this->results[$this->lastToolMessageId] = $event->message;
        }
        $message = $event->response->message();
        // Keep raw provider output for a stop that wins terminal arbitration
        // after normal execution. Tool calls must stay live until results settle.
        $this->messages[$message->getId()] = $message instanceof AssistantMessage
            && ! $message instanceof ToolCallMessage ? clone $message : $message;
        if ($message instanceof ToolCallMessage) {
            $this->lastToolMessageId = $message->getId();
            unset($this->partialCalls[$message->getId()]);
        }
        if ($message->getMetadata('stop_reason') === StoppableHttpClient::STOP_REASON) {
            $this->token->request();
        }
    }

    /** @return list<Message> */
    private function transcript(): array
    {
        $messages = [];
        foreach ($this->messages as $message) {
            $messages[] = $message;
            if (! $message instanceof ToolCallMessage) {
                continue;
            }
            foreach ($message->getToolCalls() as $call) {
                if (! $call->hasResult()) {
                    $call->setResult(ToolOutput::error('CANCELLED: generation stopped; tool not executed.'));
                }
            }
            $messages[] = $this->results[$message->getId()]
                ?? (new ToolResultMessage($message->getToolCalls()))->setId($message->getId() . ':results');
        }
        if ($this->token->isRequested() && $messages !== []) {
            $last = $messages[array_key_last($messages)];
            $last->addMetadata('generation_stopped', true);
            if ($this->partialCalls !== []) {
                // Partial JSON is not a model-ready call. Keep an explicit audit
                // cancellation, not invented arguments or an executable tool pair.
                $cancelled = [];
                foreach ($this->partialCalls as $calls) {
                    array_push($cancelled, ...array_values($calls));
                }
                $last->addMetadata('cancelled_tool_calls', $cancelled);
            }
        }
        return $messages;
    }
}
