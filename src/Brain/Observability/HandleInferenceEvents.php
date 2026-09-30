<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Agent\Observability\MessageSaving;
use OpenTelemetry\API\Trace\SpanInterface as Span;

trait HandleInferenceEvents
{
    protected Span $message;

    protected Span $inference;

    public function messageSaving(object $source, string $event, MessageSaving $messageSaving): void
    {
        $label = $this->getBaseClassName($messageSaving->message::class);

        $this->message = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.chathistory.save_message('. $label .')')
            ->startSpan();
    }

    public function messageSaved(object $source, string $event, MessageSaved $messageSaved): void
    {
        if (! isset($this->message)) {
            return;
        }

        $this->spanSetAttributes($this->message, 'neuron.Message', $this->prepareMessageItem($messageSaved->message));
        $this->message->end();
        unset($this->message);
    }

    public function inferenceStart(object $source, string $event, InferenceStart $inferenceStart): void
    {
        $label = $this->getBaseClassName($inferenceStart->message::class);

        $this->inference = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.inference.inference('. $label .')')
            ->startSpan();
    }

    public function inferenceStop(object $source, string $event, InferenceStop $inferenceStop): void
    {
        if (! isset($this->inference)) {
            return;
        }

        if ($inferenceStop->message !== false) {
            $this->spanSetAttributes($this->inference, 'neuron.Message', $this->prepareMessageItem($inferenceStop->message));
        }
        $this->spanSetAttributes($this->inference, 'neuron.Response', $this->prepareMessageItem($inferenceStop->response->message()));
        $this->inference->end();
        unset($this->inference);
    }
}
