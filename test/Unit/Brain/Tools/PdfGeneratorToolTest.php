<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Tools;

use App\Brain\Tools\PdfGeneratorTool;
use App\Services\GeneratedFileService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

final class PdfGeneratorToolTest extends TestCase
{
    public function testInvokeReturnsErrorWhenDisabled(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => false, 'defaultFormat' => 'html', 'defaultPageSize' => 'A4']]]);
        $session = $this->createMock(SessionInterface::class);
        $service = $this->createPdfGeneratorService($settings);

        $tool = new PdfGeneratorTool($service, $settings, $session);

        $result = $tool('Test content');
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('not enabled', $data['message']);
    }

    public function testInvokeReturnsErrorWhenNoThreadId(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => true, 'defaultFormat' => 'html', 'defaultPageSize' => 'A4']]]);
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnMap([
            ['threadId', null],
        ]);
        $service = $this->createPdfGeneratorService($settings);

        $tool = new PdfGeneratorTool($service, $settings, $session);

        $result = $tool('Test content');
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('No active conversation', $data['message']);
    }

    public function testGeneratedPatternMatchesPdfIds(): void
    {
        $this->assertSame(1, preg_match(GeneratedFileService::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.pdf@@'));
    }

    public function testGeneratedPatternMatchesImageIds(): void
    {
        $this->assertSame(1, preg_match(GeneratedFileService::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.png@@'));
    }

    public function testToolPropertiesIncludeContent(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => false, 'defaultFormat' => 'html', 'defaultPageSize' => 'A4']]]);
        $session = $this->createMock(SessionInterface::class);
        $service = $this->createPdfGeneratorService($settings);

        $tool = new PdfGeneratorTool($service, $settings, $session);

        $reflection = new \ReflectionClass($tool);
        $method = $reflection->getMethod('properties');
        $method->setAccessible(true);
        $properties = $method->invoke($tool);

        $names = array_map(static fn ($p) => $p->getName(), $properties);
        $this->assertContains('content', $names);
        $this->assertContains('format', $names);
        $this->assertContains('page_size', $names);
        $this->assertContains('orientation', $names);
    }

    private function createPdfGeneratorService(Settings $settings): \App\Services\PdfGeneratorService
    {
        $filesystem = $this->createMock(\League\Flysystem\Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $chatHistoryRepository = $this->createMock(\App\Repository\ChatHistoryRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        return new \App\Services\PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $chatHistoryRepository,
            $markdown,
        );
    }
}