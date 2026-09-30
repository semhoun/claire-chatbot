<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\SemanticMemoryEmbeddings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SemanticMemoryEmbeddingsTest extends TestCase
{
    public function testFloatingPointAndIntegerZeroVectorIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Empty semantic memory embedding');
        SemanticMemoryEmbeddings::validate([0.0, -0.0, 0], 3);
    }

    public function testMixedNumericVectorWithNonzeroComponentIsAccepted(): void
    {
        SemanticMemoryEmbeddings::validate([0.0, 0, -0.25], 3);
        $this->addToAssertionCount(1);
    }
}
