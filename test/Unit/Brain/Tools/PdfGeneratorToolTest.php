<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Tools;

use App\Brain\Tools\PdfGeneratorTool;
use App\Entity\File;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class PdfGeneratorToolTest extends TestCase
{
    public function testInvokeReturnsErrorWhenDisabled(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => false, 'defaultFormat' => 'html', 'defaultPageSize' => 'A4']]]);
        $session = $this->createStub(SessionInterface::class);
        $service = $this->createPdfGeneratorService($settings);

        $tool = new PdfGeneratorTool($service, $settings, $session, 'thread-123');

        $result = $tool('Test content');
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('not enabled', $data['message']);
    }

    public function testInvokeReturnsErrorWhenUserNotFound(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => true, 'defaultFormat' => 'html', 'defaultPageSize' => 'A4']]]);
        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturnMap([
            ['threadId', null],
        ]);
        $service = $this->createPdfGeneratorService($settings);

        $tool = new PdfGeneratorTool($service, $settings, $session, 'thread-123');

        $result = $tool('Test content');
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('User not found', $data['message']);
    }

    public function testGeneratedPatternMatchesPdfIds(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.pdf@@'));
    }

    public function testGeneratedPatternMatchesImageIds(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.png@@'));
    }

    public function testToolPropertiesIncludeContent(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => false, 'defaultFormat' => 'html', 'defaultPageSize' => 'A4']]]);
        $session = $this->createStub(SessionInterface::class);
        $service = $this->createPdfGeneratorService($settings);

        $tool = new PdfGeneratorTool($service, $settings, $session, 'thread-123');

        $reflection = new \ReflectionClass($tool);
        $method = $reflection->getMethod('properties');
        $properties = $method->invoke($tool);

        $names = array_map(static fn ($p) => $p->getName(), $properties);
        $this->assertContains('content', $names);
        $this->assertContains('format', $names);
        $this->assertContains('page_size', $names);
        $this->assertContains('orientation', $names);
    }

    public function testDescriptionEncouragesDesignWithinTheRendererAndResourceContract(): void
    {
        $settings = new Settings([]);
        $pdfGeneratorTool = new PdfGeneratorTool(
            $this->createPdfGeneratorService($settings),
            $settings,
            $this->createStub(SessionInterface::class),
            'thread-123',
        );

        $description = $pdfGeneratorTool->getDescription();

        $this->assertStringContainsString('color palette', $description);
        $this->assertStringContainsString('embedded <style> blocks', $description);
        $this->assertStringContainsString('override the default styles', $description);
        $this->assertStringContainsString('mPDF, not a web browser', $description);
        $this->assertStringContainsString('Unauthorized resource access fails generation', $description);
        $this->assertStringContainsString('ordinary hyperlinks remain usable', $description);
        $this->assertStringContainsString('generate_image FIRST', $description);
        $this->assertStringContainsString('NEVER call both tools in parallel', $description);
    }

    public function testForbiddenResourceReturnsAnErrorWithoutExposingItsPath(): void
    {
        $settings = new Settings(['tools' => ['pdf' => ['enabled' => true]]]);
        $user = new \App\Entity\User();
        $userRepository = $this->createStub(\App\Repository\UserRepository::class);
        $userRepository->method('getCurrentUser')->willReturn($user);
        $chatHistoryRepository = $this->createStub(\App\Repository\ChatHistoryRepository::class);
        $chatHistoryRepository->method('getCurrentUserChatHistory')->willReturn(new \App\Entity\ChatHistory());
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnMap([
            [\App\Entity\User::class, $userRepository],
            [\App\Entity\ChatHistory::class, $chatHistoryRepository],
        ]);
        $entityManager->expects($this->never())->method('persist');
        $filesystem = $this->createMock(\League\Flysystem\Filesystem::class);
        $filesystem->expects($this->never())->method('write');
        $pdfGeneratorService = new \App\Services\PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $this->createStub(\App\Services\Markdown::class),
        );
        $pdfGeneratorTool = new PdfGeneratorTool(
            $pdfGeneratorService,
            $settings,
            $this->createStub(SessionInterface::class),
            'thread-123',
        );

        $result = json_decode(
            $pdfGeneratorTool('<link rel="stylesheet" href="/private/sensitive.css"><p>Report</p>'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Error generating PDF:', $result['message']);
        $this->assertStringNotContainsString('/private/sensitive.css', $result['message']);
        $this->assertArrayNotHasKey('id', $result);
    }

    private function createPdfGeneratorService(Settings $settings): \App\Services\PdfGeneratorService
    {
        $filesystem = $this->createStub(\League\Flysystem\Filesystem::class);
        $entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

        return new \App\Services\PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );
    }
}
