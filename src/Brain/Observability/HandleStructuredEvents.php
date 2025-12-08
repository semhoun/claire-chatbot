<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Observability\Events\Deserialized;
use NeuronAI\Observability\Events\Deserializing;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\SchemaGenerated;
use NeuronAI\Observability\Events\SchemaGeneration;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;
use OpenTelemetry\API\Trace\SpanInterface as Span;

trait HandleStructuredEvents
{
    protected Span $schema;

    protected Span $extract;

    protected Span $deserialize;

    protected Span $validate;

    protected function schemaGeneration(object $source, string $event, SchemaGeneration $schemaGeneration): void
    {
        $this->schema = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.structured-output.schema_generate('. $this->getBaseClassName($schemaGeneration->class) .')')
            ->startSpan();
    }

    protected function schemaGenerated(object $source, string $event, SchemaGenerated $schemaGenerated): void
    {
        $this->spanSetAttributes($this->schema, 'neuron.Schema', $schemaGenerated->schema);
        $this->schema->end();
    }

    protected function extracting(object $source, string $event, Extracting $extracting): void
    {
        $this->extract = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.structured-output.extract_output')
            ->startSpan();
    }

    protected function extracted(object $source, string $event, Extracted $extracted): void
    {
        if (! isset($this->extract)) {
            return;
        }

        $this->spanSetAttributes($this->extract, 'neuron', [
            'Data' => [
                'response' => $extracted->message->jsonSerialize(),
                'json' => $extracted->json,
            ],
            'Schema' => $extracted->schema,
        ]);
        $this->extract->end();
    }

    protected function deserializing(object $source, string $event, Deserializing $deserializing): void
    {
        $this->deserialize = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.structured-output.deserialize('. $this->getBaseClassName($deserializing->class) .')')
            ->startSpan();
    }

    protected function deserialized(object $source, string $event, Deserialized $deserialized): void
    {
        if (! isset($this->deserialize)) {
            return;
        }

        $this->deserialize->end();
    }

    protected function validating(object $source, string $event, Validating $validating): void
    {
        $this->validate = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.structured-output.validate('. $this->getBaseClassName($validating->class) .')')
            ->startSpan();
    }

    protected function validated(object $source, string $event, Validated $validated): void
    {
        if (! isset($this->validate)) {
            return;
        }

        $this->spanSetAttributes($this->validate, 'neuron.Json', \json_decode($validated->json, true));
        if ($validated->violations !== []) {
            $this->spanSetAttributes($this->validate, 'neuron.Violations', $validated->violations);
        }
    }
}
