<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\RAG\Observability\PostProcessed;
use NeuronAI\RAG\Observability\PostProcessing;
use NeuronAI\RAG\Observability\PreProcessed;
use NeuronAI\RAG\Observability\PreProcessing;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\RAG\Observability\Retrieving;

trait HandleRagEvents
{
    public function ragRetrieving(object $source, string $event, Retrieving $retrieving): void
    {
        $questionText = $retrieving->question->getContent();
        $id = \md5($questionText.$retrieving->question->getRole());

        $this->spans[$id] = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.retrieval.vector_retrieval('. $questionText .')')
            ->startSpan();
    }

    public function ragRetrieved(object $source, string $event, Retrieved $retrieved): void
    {
        $questionText = $retrieved->question->getContent();
        $id = \md5($questionText.$retrieved->question->getRole());

        if (\array_key_exists($id, $this->spans)) {
            $span = $this->spans[$id];
            $this->spanSetAttributes($span, 'neuron.Data', [
                'question' => $questionText,
                'documents' => \count($retrieved->documents),
            ]);
            $span->end();
            unset($this->spans[$id]);
        }
    }

    public function preProcessing(object $source, string $event, PreProcessing $preProcessing): void
    {
        $span = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.preprocessing.('. $preProcessing->processor .')')
            ->startSpan();
        $this->spanSetAttributes($span, 'neuron.Original', $preProcessing->original->jsonSerialize());

        $this->spans[$preProcessing->processor] = $span;
    }

    public function preProcessed(object $source, string $event, PreProcessed $preProcessed): void
    {
        if (\array_key_exists($preProcessed->processor, $this->spans)) {
            $span = $this->spans[$preProcessed->processor];
            $this->spanSetAttributes($span, 'neuron.Processed', $preProcessed->processed->jsonSerialize());
            $span->end();
            unset($this->spans[$preProcessed->processor]);
        }
    }

    public function postProcessing(object $source, string $event, PostProcessing $postProcessing): void
    {
        $span = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.postprocessing.('. $postProcessing->processor .')')
            ->startSpan();
        $this->spanSetAttributes($span, 'neuron', [
            'question' => $postProcessing->question->jsonSerialize(),
            'documents' => $postProcessing->documents,
        ]);
        $this->spans[$postProcessing->processor] = $span;
    }

    public function postProcessed(object $source, string $event, PostProcessed $postProcessed): void
    {
        if (\array_key_exists($postProcessed->processor, $this->spans)) {
            $span = $this->spans[$postProcessed->processor];
            $this->spanSetAttributes($span, 'neuron.PostProcess', $postProcessed->documents);
            $span->end();
            unset($this->spans[$postProcessed->processor]);
        }
    }
}
