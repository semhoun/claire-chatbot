<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface as Span;

trait HasInstrumentation
{
    protected ?CachedInstrumentation $instrumentation = null;

    protected ?Span $activeSpan = null;

    protected function getInstrumentation(): CachedInstrumentation
    {
        return $this->instrumentation ??= new CachedInstrumentation('neuron.ai');
    }

    protected function otelStartSpan(string $name): Span
    {
        $this->activeSpan = $this->getInstrumentation()->tracer()->spanBuilder($name)

            ->startSpan();

        return $this->activeSpan;
    }

    protected function otelStopSpan(): void
    {
        if ($this->activeSpan === null) {
            return;
        }

        $this->activeSpan->end();

        $this->activeSpan = null;
    }
}
