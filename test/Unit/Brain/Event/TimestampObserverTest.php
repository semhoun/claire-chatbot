<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Event;

use App\Brain\Event\TimestampObserver;
use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Agent\Observability\MessageSaving;
use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

final class TimestampObserverTest extends TestCase
{
    public function testAddsTimestampToMessageOnMessageSavingEvent(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');
        $event = new MessageSaving($message);

        $observer($event);

        $timestamp = $message->getMetadata('timestamp');
        $this->assertNotNull($timestamp);
        $this->assertIsString($timestamp);

        $timestampDate = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
        $this->assertInstanceOf(DateTimeImmutable::class, $timestampDate);
    }

    public function testDoesNotOverwriteExistingTimestamp(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');
        $existingTs = '2024-01-15T10:30:00+00:00';
        $message->addMetadata('timestamp', $existingTs);

        $observer(new MessageSaving($message));

        $this->assertSame($existingTs, $message->getMetadata('timestamp'));
    }

    public function testIgnoresNonMessageSavingEvents(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');

        $workflow = new Workflow();
        $observer->subscribeTo($workflow);
        $workflow->getEventDispatcher()->dispatch(new MessageSaved($message));

        $this->assertNull($message->getMetadata('timestamp'));
    }

    public function testRepeatedSubscriptionIsIdempotent(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');

        $workflow = $this->createMock(Workflow::class);
        $workflow->expects($this->once())->method('subscribe')->with(MessageSaving::class, $observer)->willReturnSelf();
        $observer->subscribeTo($workflow);
        $observer->subscribeTo($workflow);
    }

    public function testTimestampFormatIsAtom(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('format test');

        $observer(new MessageSaving($message));

        $timestamp = $message->getMetadata('timestamp');
        $this->assertNotNull($timestamp);

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed);
    }

    public function testRealAgentAddsTimestampBeforeStoreAppendAcrossRuns(): void
    {
        $store = new class extends \NeuronAI\Chat\History\InMemoryMessageStore {
            public array $timestamps = [];

            public function append(string $threadId, \NeuronAI\Chat\Messages\Message $message): void
            {
                $this->timestamps[] = $message->getMetadata('timestamp');
                parent::append($threadId, $message);
            }
        };
        $provider = new \NeuronAI\Testing\FakeAIProvider(
            new \NeuronAI\Chat\Messages\AssistantMessage('one'),
            new \NeuronAI\Chat\Messages\AssistantMessage('two'),
        );
        $agent = new \NeuronAI\Agent\Agent('timestamp-test');
        $agent->setAiProvider($provider)->setMessageStore($store);
        $observer = new TimestampObserver();
        $observer->subscribeTo($agent);
        $observer->subscribeTo($agent);
        foreach (['first', 'second'] as $question) {
            $stream = $agent->stream(new UserMessage($question));
            iterator_to_array($stream);
            self::assertNotNull($stream->getReturn()->getMessage());
        }
        self::assertCount(4, $store->timestamps);
        foreach ($store->timestamps as $timestamp) {
            self::assertIsString($timestamp);
            self::assertInstanceOf(DateTimeImmutable::class,
                DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp));
        }
        self::assertSame(2, $provider->getCallCount());
    }
}
