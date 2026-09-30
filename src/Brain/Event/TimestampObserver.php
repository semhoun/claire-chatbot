<?php

declare(strict_types=1);

namespace App\Brain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Agent\Observability\MessageSaving;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Workflow;

final class TimestampObserver
{
    /** @var \WeakMap<Workflow, bool> */
    private \WeakMap $weakMap;

    public function __construct()
    {
        $this->weakMap = new \WeakMap();
    }

    public function __invoke(MessageSaving $messageSaving): void
    {
        $this->addTimestampToMessage($messageSaving->message);
    }

    public function subscribeTo(Workflow $workflow): void
    {
        if (! isset($this->weakMap[$workflow])) {
            $workflow->subscribe(MessageSaving::class, $this);
            $this->weakMap[$workflow] = true;
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
