<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Tools;

use App\Brain\Tools\TextToSpeechTool;
use App\Services\Audio\AudioServiceInterface;
use App\Services\AudioGeneratorService;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class TextToSpeechToolTest extends TestCase
{
    public function testExposesTtsParametersAndReturnsProviderErrors(): void
    {
        $audioService = $this->createStub(AudioServiceInterface::class);
        $audioService->method('isAvailable')->willReturn(false);
        $audioService->method('voices')->willReturn([
            ['id' => 'voice-1', 'label' => 'Voix française'],
        ]);
        $textToSpeechTool = new TextToSpeechTool(
            new AudioGeneratorService(
                $audioService,
                $this->createStub(Filesystem::class),
                $this->createStub(EntityManagerInterface::class),
            ),
            $audioService,
            $this->createStub(SessionInterface::class),
            'thread-1',
        );

        $result = json_decode(
            $textToSpeechTool('Bonjour'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $reflectionMethod = new \ReflectionMethod(
            $textToSpeechTool,
            'properties',
        );
        $properties = $reflectionMethod->invoke($textToSpeechTool);
        $names = array_map(
            static fn (object $property): string => $property->getName(),
            $properties,
        );

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('not available', $result['message']);
        self::assertSame(['text', 'voice', 'filename'], $names);
    }
}
