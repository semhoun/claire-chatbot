<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Agent\Observability\Deserialized;
use NeuronAI\Agent\Observability\Deserializing;
use NeuronAI\Agent\Observability\Extracted;
use NeuronAI\Agent\Observability\Extracting;
use NeuronAI\Agent\Observability\SchemaGenerated;
use NeuronAI\Agent\Observability\SchemaGeneration;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\Agent\Observability\Validating;
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
        unset($this->schema);
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
        unset($this->extract);
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
        unset($this->deserialize);
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

        $this->spanSetAttributes($this->validate, 'neuron.Json', \json_decode($validated->json, true, flags: JSON_THROW_ON_ERROR));
        if ($validated->violations !== []) {
            $this->spanSetAttributes($this->validate, 'neuron.Violations', $validated->violations);
        }
        $this->validate->end();
        unset($this->validate);
    }
}
