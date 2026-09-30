<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GenerationExecution;
use App\Services\GenerationStopToken;
use App\Services\GenerationStopHistoryTrimmer;
use App\Brain\Middleware\ToolCalls;
use App\Brain\Tools\MessagePostProcessorInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

final class GenerationExecutionTest extends TestCase
{
    private function response(array $deltas, ?string $finish = 'stop'): Response
    {
        $body = '';
        foreach ($deltas as $delta) {
            $body .= 'data: ' . json_encode(['choices' => [['delta' => $delta, 'finish_reason' => null]]], JSON_THROW_ON_ERROR) . "\n\n";
        }
        if ($finish !== null) {
            $body .= 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => $finish]]], JSON_THROW_ON_ERROR) . "\n\n";
            $body .= "data: [DONE]\n\n";
        }
        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    private function calls(): array
    {
        return ['content' => 'Working.', 'tool_calls' => [
            ['index' => 0, 'id' => 'one', 'type' => 'function', 'function' => ['name' => 'work', 'arguments' => '{}']],
            ['index' => 1, 'id' => 'two', 'type' => 'function', 'function' => ['name' => 'work', 'arguments' => '{}']],
        ]];
    }

    private function agent(GenerationStopToken $token, array $responses, ?Tool $tool = null): array
    {
        $mock = new MockHandler($responses);
        $client = new GuzzleHttpClient(handler: HandlerStack::create($mock));
        $provider = new OpenAI('test', 'test', httpClient: $token->httpClient($client));
        $store = new InMemoryMessageStore();
        $agent = (new class extends Agent {
            protected function resources(): AgentResources
            {
                $resources = parent::resources();
                return new AgentResources($resources->provider,
                    new ChatHistory($this->resolveMessageStore(), $this->getThreadId(),
                        trimmer: new GenerationStopHistoryTrimmer()),
                    $resources->instructions, $resources->tools);
            }
        })->setThreadId('test')->setAiProvider($provider)->setMessageStore($store);
        if ($tool !== null) {
            $agent->setTools([$tool]);
        }
        return [$agent, $mock, $store, $provider];
    }

    private function tool(object $counter, ?GenerationStopToken $stopDuringTool = null): Tool
    {
        return new class ($counter, $stopDuringTool) extends Tool {
            protected string $name = 'work';
            public function __construct(private object $counter, private ?GenerationStopToken $token)
            {
            }
            public function __invoke(): string
            {
                ++$this->counter->runs;
                $this->token?->request();
                return 'finished';
            }
        };
    }

    private function postProcessingTool(object $counter): Tool
    {
        return new class ($counter) extends Tool implements MessagePostProcessorInterface {
            protected string $name = 'work';

            public function __construct(private object $counter)
            {
            }

            public function __invoke(): string
            {
                ++$this->counter->runs;
                return 'finished';
            }

            public function postProcessMessage(Message $message, ToolCall $call): Message
            {
                ++$this->counter->postprocessed;
                // Exercise in-place block mutation as well as message metadata.
                $message->getContentBlocks()[0]->content .= ' [generated:' . $call->getCallId() . ']';
                $message->addMetadata('automated_marker', true);
                return $message;
            }
        };
    }

    public function testStopBeforeEntryPreservesUserWithoutProviderInvocation(): void
    {
        $token = new GenerationStopToken(static fn (): bool => true);
        [$agent, $http] = $this->agent($token, [$this->response([['content' => 'unused']])]);
        $user = new UserMessage('hello');
        $stream = (new GenerationExecution($token))->stream($agent, $user);
        self::assertSame([], iterator_to_array($stream));
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame([$user], $stream->getReturn()->messages);
        self::assertSame(1, $http->count());
    }

    public function testStopBetweenTextChunksRetainsOnlyPublishedPartialText(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        [$agent] = $this->agent($token, [$this->response([['content' => 'first'], ['content' => 'second']])]);
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('hello'));
        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $token->request();
            }
        }
        $result = $stream->getReturn();
        self::assertTrue($result->stopped);
        self::assertCount(2, $result->messages);
        self::assertSame('first', $result->messages[1]->getContent());
    }

    public function testStopDuringRunningToolKeepsResultAndCancelsSecondInSameBatch(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent, $http, $store] = $this->agent($token, [$this->response([$this->calls()], 'tool_calls')], $this->tool($counter, $token));
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        $result = $stream->getReturn();
        self::assertTrue($result->stopped);
        self::assertSame(1, $counter->runs);
        self::assertSame(0, $http->count());
        self::assertCount(3, $result->messages);
        self::assertInstanceOf(ToolCallMessage::class, $result->messages[1]);
        self::assertInstanceOf(ToolResultMessage::class, $result->messages[2]);
        $calls = $result->messages[2]->getToolCalls();
        self::assertSame('finished', $calls[0]->getResult());
        self::assertInstanceOf(ToolOutput::class, $calls[1]->getResult());
        self::assertTrue($calls[1]->getResult()->isError());
        self::assertStringContainsString('CANCELLED', $calls[1]->getResult()->getText());
        foreach ($result->messages as $message) {
            $store->append('test', $message);
        }
        // A fresh turn, never an inputless recovery, works with the retained pairs.
        $next = new OpenAI('test', 'test', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([$this->response([['content' => 'next']])])),
        ));
        $nextStream = $agent->setAiProvider($next)->stream(new UserMessage('continue'));
        iterator_to_array($nextStream);
        self::assertSame('next', $nextStream->getReturn()->getMessage()->getContent());
        self::assertSame(1, $counter->runs);
    }

    public function testEventGateCatchesStopAfterResumeCheckBeforeActualExecution(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent] = $this->agent($token, [$this->response([$this->calls()], 'tool_calls')], $this->tool($counter));
        $agent->subscribe(ToolCalling::class, static function () use ($token): void { $token->request(); });
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame(0, $counter->runs);
    }

    public function testStopAtToolChunkPreventsEveryCall(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent] = $this->agent($token, [$this->response([$this->calls()], 'tool_calls')], $this->tool($counter));
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        foreach ($stream as $chunk) {
            if ($chunk instanceof ToolCallChunk) {
                $token->request();
            }
        }
        self::assertSame(0, $counter->runs);
        self::assertTrue($stream->getReturn()->stopped);
    }

    public function testNormalMultiToolRunIsInvokedOnlyOnce(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent, $http] = $this->agent($token, [
            $this->response([['content' => 'Working.'], $this->calls()], 'tool_calls'),
            $this->response([['content' => 'done']]),
        ], $this->tool($counter));
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        self::assertFalse($stream->getReturn()->stopped);
        self::assertSame('done', $stream->getReturn()->state->getMessage()->getContent());
        self::assertSame(2, $counter->runs);
        self::assertSame(0, $http->count());
        self::assertCount(4, $stream->getReturn()->messages);
        self::assertSame('Working.', $stream->getReturn()->messages[1]->getContent());
    }

    public function testTruncatedProviderWithoutStopRemainsFailure(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        [$agent] = $this->agent($token, [$this->response([['content' => 'broken']], null)]);
        $this->expectException(\NeuronAI\Exceptions\ProviderException::class);
        iterator_to_array((new GenerationExecution($token))->stream($agent, new UserMessage('hello')));
    }

    public function testHttpDecoratorProducesStoppedProviderCompletionAndLatches(): void
    {
        $requested = false;
        $token = new GenerationStopToken(static function () use (&$requested): bool { return $requested; });
        [, , , $provider] = $this->agent($token, [$this->response([['content' => 'partial'], ['content' => 'unused']])]);
        $stream = $provider->stream(new UserMessage('hello'));
        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $requested = true;
            }
        }
        self::assertSame('stopped', $stream->getReturn()->message()->getMetadata('stop_reason'));
        self::assertSame('partial', $stream->getReturn()->message()->getContent());
        $requested = false;
        self::assertTrue($token->isRequested());
    }

    public function testHttpStopBeforeFirstTokenHasNoInventedAssistantResponse(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        [$agent] = $this->agent($token, [function () use ($token): Response {
            $token->request();
            return $this->response([['content' => 'unused']]);
        }]);
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('hello'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertCount(1, $stream->getReturn()->messages);
        self::assertInstanceOf(UserMessage::class, $stream->getReturn()->messages[0]);
    }

    public function testMalformedPartialArgumentsCannotExecuteAfterStop(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        $delta = $this->calls();
        $delta['tool_calls'][0]['function']['arguments'] = '{"unfinished":';
        [$agent] = $this->agent($token, [$this->response([$delta], 'tool_calls')], $this->tool($counter));
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        foreach ($stream as $chunk) {
            if ($chunk instanceof ToolArgumentChunk) {
                $token->request();
            }
        }
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame(0, $counter->runs);
        $messages = $stream->getReturn()->messages;
        self::assertSame('cancelled', $messages[array_key_last($messages)]
            ->getMetadata('cancelled_tool_calls')[0]['status']);
    }

    public function testLockFailureNeverBecomesStopOrRunsTool(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent] = $this->agent($token, [$this->response([$this->calls()], 'tool_calls')], $this->tool($counter));
        $lost = false;
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'),
            static function () use (&$lost): void {
                if ($lost) {
                    throw new \RuntimeException('lock lost');
                }
            });
        try {
            foreach ($stream as $chunk) {
                if ($chunk instanceof ToolCallChunk) {
                    $lost = true;
                }
            }
            self::fail('Lock failure was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('lock lost', $error->getMessage());
        }
        self::assertSame(0, $counter->runs);
        self::assertFalse($token->isRequested());
    }

    public function testHelperCannotBeReplayed(): void
    {
        $token = new GenerationStopToken(static fn (): bool => true);
        [$agent] = $this->agent($token, []);
        $execution = new GenerationExecution($token);
        iterator_to_array($execution->stream($agent, new UserMessage('hello')));
        $this->expectException(\LogicException::class);
        iterator_to_array($execution->stream($agent, new UserMessage('again')));
    }

    public function testWelcomeCannotEnterCooperativeExecution(): void
    {
        $token = new GenerationStopToken(static fn (): bool => true);
        [$agent] = $this->agent($token, []);
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array((new GenerationExecution($token))->stream($agent, []));
    }

    public function testProviderStoppedReasonPreventsNormalCompletion(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        [$agent] = $this->agent($token, [$this->response([['content' => 'partial']], 'stopped')]);
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame('partial', $stream->getReturn()->messages[1]->getContent());
    }

    public function testPerCallGateStopsSecondCallEvenWithoutConsumerIntervention(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent] = $this->agent($token, [$this->response([$this->calls()], 'tool_calls')], $this->tool($counter));
        $calls = 0;
        $agent->subscribe(ToolCalling::class, static function () use ($token, &$calls): void {
            if (++$calls === 2) {
                $token->request();
            }
        });
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame(1, $counter->runs);
        self::assertSame('finished', $stream->getReturn()->messages[2]->getToolCalls()[0]->getResult());
        self::assertTrue($stream->getReturn()->messages[2]->getToolCalls()[1]->getResult()->isError());
    }

    public function testUserOnlyStoppedHistoryAllowsNextTurnWithoutSyntheticAssistant(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'stopped', trimmer: new GenerationStopHistoryTrimmer());
        $first = (new UserMessage('first'))->addMetadata('generation_stopped', true);
        $second = new UserMessage('second');
        $history->addMessage($first)->addMessage($second);
        self::assertSame([$first, $second], $history->getMessages());
    }

    public function testStopMarkerCannotExcuseDanglingToolCalls(): void
    {
        $trimmer = new GenerationStopHistoryTrimmer();
        $dangling = (new ToolCallMessage(null, [new \NeuronAI\Tools\ToolCall('work', 'one')]))
            ->addMetadata('generation_stopped', true);
        $this->expectException(\NeuronAI\Exceptions\ChatHistoryException::class);
        $trimmer->trim([new UserMessage('first'), $dangling, new UserMessage('second')], 50000);
    }

    public function testProviderNormalizedEmptyArgumentsCannotBypassStopGate(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        $delta = $this->calls();
        $delta['tool_calls'][0]['function']['arguments'] = '';
        [$agent] = $this->agent($token, [$this->response([$delta], 'tool_calls')], $this->tool($counter));
        $agent->subscribe(InferenceStop::class, static function () use ($token): void { $token->request(); });
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame(0, $counter->runs);
        self::assertCount(2, $stream->getReturn()->messages[2]->getToolCalls());
        self::assertTrue($stream->getReturn()->messages[2]->getToolCalls()[0]->getResult()->isError());
    }

    public function testConcurrentStopDoesNotHideProviderFailure(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        [$agent] = $this->agent($token, [static function () use ($token): never {
            $token->request();
            throw new \NeuronAI\Exceptions\ProviderException('provider failed');
        }]);
        $this->expectException(\NeuronAI\Exceptions\ProviderException::class);
        $this->expectExceptionMessage('provider failed');
        iterator_to_array((new GenerationExecution($token))->stream($agent, new UserMessage('hello')));
    }

    public function testTerminalRaceSnapshotIsRawWhileFinalStateIsPostprocessed(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0, 'postprocessed' => 0];
        [$agent] = $this->agent($token, [
            $this->response([['content' => 'Working.'], $this->calls()], 'tool_calls'),
            $this->response([['content' => 'done']]),
        ], $this->postProcessingTool($counter));
        $agent->addMiddleware(AgentEndNode::class, new ToolCalls());
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        $result = $stream->getReturn();

        self::assertFalse($result->stopped);
        self::assertSame('done [generated:one] [generated:two]', $result->state->getMessage()->getContent());
        self::assertTrue($result->state->getMessage()->getMetadata('automated_marker'));
        self::assertSame(2, $counter->runs);
        self::assertSame(2, $counter->postprocessed);
        $raw = $result->messages[3];
        self::assertSame('done', $raw->getContent());
        self::assertNull($raw->getMetadata('automated_marker'));
        self::assertSame($result->state->getMessage()->getId(), $raw->getId());
        self::assertNotSame($result->state->getMessage(), $raw);
        self::assertNotSame($result->state->getMessage()->getContentBlocks()[0], $raw->getContentBlocks()[0]);
        self::assertSame('Working.', $result->messages[1]->getContent());
        self::assertSame('finished', $result->messages[1]->getToolCalls()[0]->getResult());
        self::assertSame($result->messages[1]->getToolCalls()[0], $result->messages[2]->getToolCalls()[0]);

        // Simulate SQL stop winning only after normal helper completion. The
        // caller selects raw messages, without rerunning or undoing executed tools.
        $token->request();
        self::assertSame('done', $raw->getContent());
        self::assertFalse($result->stopped);
        self::assertSame(2, $counter->runs);
    }

    public function testStopAfterInferenceGatesEndNodeBeforePostprocessing(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0, 'postprocessed' => 0];
        [$agent] = $this->agent($token, [
            $this->response([$this->calls()], 'tool_calls'),
            $this->response([['content' => 'done']]),
        ], $this->postProcessingTool($counter));
        $agent->addMiddleware(AgentEndNode::class, new ToolCalls());
        $agent->subscribe(InferenceStop::class, static function (InferenceStop $event) use ($token): void {
            if (!$event->response->message() instanceof ToolCallMessage) {
                $token->request();
            }
        });
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        iterator_to_array($stream);
        self::assertTrue($stream->getReturn()->stopped);
        self::assertSame(2, $counter->runs);
        self::assertSame(0, $counter->postprocessed);
        self::assertSame('done', $stream->getReturn()->messages[3]->getContent());
    }

    public function testScopedProviderAndListenersDoNotStopNextTurn(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        [$agent, , $store, $provider] = $this->agent($token, []);
        $http = new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
            $this->response([['content' => 'partial'], ['content' => 'unused']]),
            $this->response([['content' => 'next']]),
        ])));
        $provider->setHttpClient($http);
        $generationProvider = clone $provider;
        $generationProvider->setHttpClient($token->httpClient($generationProvider->getHttpClient()));
        $generationAgent = $agent->for('test')->setAiProvider($generationProvider);
        $stream = (new GenerationExecution($token))->stream($generationAgent, new UserMessage('first'));
        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $token->request();
            }
        }
        foreach ($stream->getReturn()->messages as $message) {
            $store->append('test', $message);
        }
        self::assertSame($provider, $agent->getProvider());
        self::assertSame($http, $provider->getHttpClient());
        self::assertTrue($token->isRequested());
        $next = $agent->stream(new UserMessage('second'));
        iterator_to_array($next);
        self::assertSame('next', $next->getReturn()->getMessage()->getContent());
        self::assertNull($next->getReturn()->getMessage()->getMetadata('generation_stopped'));
    }

    public function testOwnershipGuardRunsBeforeEntryAndEveryContinuation(): void
    {
        $token = new GenerationStopToken(static fn (): bool => false);
        $counter = (object) ['runs' => 0];
        [$agent] = $this->agent($token, [
            $this->response([$this->calls()], 'tool_calls'),
            $this->response([['content' => 'one'], ['content' => 'two']]),
        ], $this->tool($counter));
        $checks = 0;
        $chunks = 0;
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'),
            static function () use (&$checks): void { ++$checks; });
        foreach ($stream as $chunk) {
            self::assertSame(++$chunks, $checks);
        }
        self::assertSame($chunks + 1, $checks);
        self::assertSame(2, $counter->runs);
        self::assertFalse($stream->getReturn()->stopped);
    }

    public function testPreRequestedStopDoesNotResolveProviderOrResources(): void
    {
        $agent = (new class extends Agent {
            protected function resources(): AgentResources
            {
                throw new \RuntimeException('Resources must not be resolved.');
            }

            protected function provider(): \NeuronAI\Providers\AIProviderInterface
            {
                throw new \RuntimeException('Provider must not be resolved.');
            }
        })->setThreadId('test');
        $token = new GenerationStopToken(static fn (): bool => true);
        $stream = (new GenerationExecution($token))->stream($agent, new UserMessage('run'));
        self::assertSame([], iterator_to_array($stream));
        self::assertTrue($stream->getReturn()->stopped);
    }
}
