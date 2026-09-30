<?php

declare(strict_types=1);

namespace App\Services;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use RuntimeException;

/** Refuse incompatible or malformed embeddings before vendor similarity arithmetic. */
final readonly class SemanticMemoryEmbeddings implements EmbeddingsProviderInterface
{
    public function __construct(private EmbeddingsProviderInterface $embeddingsProvider, private int $dimensions)
    {
        if ($dimensions < 1) {
            throw new \InvalidArgumentException('Invalid semantic memory dimensions');
        }
    }

    public function embedText(string $text): array
    {
        $embedding = $this->embeddingsProvider->embedText($text);
        self::validate($embedding, $this->dimensions);
        return $embedding;
    }

    public function embedDocument(Document $document): Document
    {
        return $document->setEmbedding($this->embedText($document->getContent()));
    }

    /**
     * @param array<int, Document> $documents
     *
     * @return array<int, Document>
     */
    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }

    /** @param array<array-key, mixed> $embedding */
    public static function validate(array $embedding, int $dimensions): void
    {
        if (! array_is_list($embedding) || count($embedding) !== $dimensions) {
            throw new RuntimeException('Incompatible semantic memory embedding');
        }

        $nonzero = false;
        foreach ($embedding as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                throw new RuntimeException('Invalid semantic memory embedding');
            }

            $nonzero = $nonzero || (float) $value !== 0.0;
        }

        if (! $nonzero) {
            throw new RuntimeException('Empty semantic memory embedding');
        }
    }
}
