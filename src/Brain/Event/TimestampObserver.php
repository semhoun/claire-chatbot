<?php

declare(strict_types=1);

namespace App\Brain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\MessageSaving;
use NeuronAI\Observability\ObserverInterface;

final class TimestampObserver implements ObserverInterface
{
    public function onEvent(string $event, object $source, mixed $data = null): void
    {
        if ($event === 'message-saving' && $data instanceof MessageSaving) {
            $this->addTimestampToMessage($data->message);
        }
    }

    private function addTimestampToMessage(Message $message): void
    {
        // Add timestamp to message if not already present
        if ($message->getMetadata('timestamp') === null) {
            $message->addMetadata(
                'timestamp',
                new DateTimeImmutable()->format(DateTimeInterface::ATOM)
            );
        }
    }
}
