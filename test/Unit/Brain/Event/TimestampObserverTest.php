<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Event;

use App\Brain\Event\TimestampObserver;
use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\MessageSaving;
use PHPUnit\Framework\TestCase;

final class TimestampObserverTest extends TestCase
{
    public function testAddsTimestampToMessageOnMessageSavingEvent(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');
        $event = new MessageSaving($message);

        $observer->onEvent('message-saving', new \stdClass(), $event);

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

        $observer->onEvent('message-saving', new \stdClass(), new MessageSaving($message));

        $this->assertSame($existingTs, $message->getMetadata('timestamp'));
    }

    public function testIgnoresNonMessageSavingEvents(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');

        $observer->onEvent('message-saved', new \stdClass(), null);
        $observer->onEvent('inference-start', new \stdClass(), null);
        $observer->onEvent('chat-start', new \stdClass(), null);
        $observer->onEvent('error', new \stdClass(), null);

        $this->assertNull($message->getMetadata('timestamp'));
    }

    public function testIgnoresMessageSavingEventWithWrongDataType(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('Hello');

        $observer->onEvent('message-saving', new \stdClass(), new \stdClass());

        $this->assertNull($message->getMetadata('timestamp'));
    }

    public function testTimestampFormatIsAtom(): void
    {
        $observer = new TimestampObserver();
        $message = new UserMessage('format test');

        $observer->onEvent('message-saving', new \stdClass(), new MessageSaving($message));

        $timestamp = $message->getMetadata('timestamp');
        $this->assertNotNull($timestamp);

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed);
    }
}
