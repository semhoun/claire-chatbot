<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueSerializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueSerializerTest extends TestCase
{
    public function testEncodeAndDecodeRoundTrip(): void
    {
        $serializer = new QueueSerializer();
        $payload = ['job' => 'example', 'attempt' => 2];

        $encoded = $serializer->encode($payload);

        $this->assertSame($payload, $serializer->decode($encoded));
    }

    public function testDecodeThrowsForInvalidJson(): void
    {
        $serializer = new QueueSerializer();

        $this->expectException(RuntimeException::class);

        $serializer->decode('{invalid');
    }
}
