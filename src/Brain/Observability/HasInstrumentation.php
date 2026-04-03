<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use JsonException;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanInterface as Span;

trait HasInstrumentation
{
    protected ?CachedInstrumentation $instrumentation = null;

    protected ?Span $activeSpan = null;

    protected function getInstrumentation(): CachedInstrumentation
    {
        return $this->instrumentation ??= new CachedInstrumentation('neuron.ai');
    }

    protected function otelLog(string $message, mixed $data = null): void
    {
        $logRecord = new LogRecord($message);
        if ($data !== null) {
            $logRecord->setAttributes($data);
        }

        $this->getInstrumentation()->logger()->emit($logRecord);
    }

    protected function otelStartSpan(string $name): Span
    {
        $this->activeSpan = $this->getInstrumentation()->tracer()->spanBuilder($name)
            ->startSpan();

        return $this->activeSpan;
    }

    protected function otelSetSpanAttribute(string $key, mixed $value): void
    {
        if ($this->activeSpan === null) {
            return;
        }

        try {
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }
        catch (JsonException $e) {
            return;
        }

        $this->activeSpan->setAttribute($key, $value);
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
