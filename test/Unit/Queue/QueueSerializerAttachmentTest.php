<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueSerializer;
use PHPUnit\Framework\TestCase;

final class QueueSerializerAttachmentTest extends TestCase
{
    public function testEncodeAndDecodePreserveSerializedAttachments(): void
    {
        $serializer = new QueueSerializer();
        $payload = [
            'message' => 'Analyse ce fichier',
            'attachments' => [
                'uploadedFiles' => [
                    [
                        'filename' => 'notes.txt',
                        'mimeType' => 'text/plain',
                        'content' => base64_encode('bonjour'),
                    ],
                ],
                'fileIds' => [
                    [
                        'filename' => 'stored.pdf',
                        'mimeType' => 'application/pdf',
                        'content' => base64_encode('%PDF-1.4'),
                    ],
                ],
            ],
        ];

        $encoded = $serializer->encode($payload);
        $decoded = $serializer->decode($encoded);

        $this->assertSame($payload, $decoded);
    }
}
