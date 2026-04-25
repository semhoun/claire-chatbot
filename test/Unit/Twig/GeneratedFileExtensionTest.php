<?php

declare(strict_types=1);

namespace App\Test\Unit\Twig;

use App\Entity\File;
use App\Services\Twig\GeneratedFileExtension;
use PHPUnit\Framework\TestCase;

final class GeneratedFileExtensionTest extends TestCase
{
    private GeneratedFileExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new GeneratedFileExtension();
    }

    public function testProcessGeneratedFilesWithImageId(): void
    {
        $content = 'Here is an image: @@GENERATED@@user123@abc-def.png@@';
        $result = $this->extension->processGeneratedFiles($content);

        $this->assertStringContainsString('/files/serve/', $result);
        $this->assertStringContainsString('class="generated-image"', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }

    public function testProcessGeneratedFilesWithPdfId(): void
    {
        $content = 'Here is a PDF: @@GENERATED@@user123@abc-def.pdf@@';
        $result = $this->extension->processGeneratedFiles($content);

        $this->assertStringContainsString('/files/serve/', $result);
        $this->assertStringContainsString('class="generated-pdf"', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }

    public function testProcessGeneratedFilesPlaceholderWithPdfId(): void
    {
        $content = 'Here is a PDF: @@GENERATED@@user123@abc-def.pdf@@';
        $result = $this->extension->processGeneratedFilesPlaceholder($content);

        $this->assertStringContainsString('generated-pdf-placeholder', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }

    public function testProcessGeneratedFilesPlaceholderWithImageId(): void
    {
        $content = 'Here is an image: @@GENERATED@@user123@abc-def.png@@';
        $result = $this->extension->processGeneratedFilesPlaceholder($content);

        $this->assertStringContainsString('generated-image-placeholder', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }

    public function testExtractGeneratedFilesReturnsAllMatches(): void
    {
        $content = 'Image: @@GENERATED@@user1@img.png@@ and PDF: @@GENERATED@@user2@doc.pdf@@';
        $result = $this->extension->extractGeneratedFiles($content);

        $this->assertCount(2, $result);
        $this->assertSame('@@GENERATED@@user1@img.png@@', $result[0]);
        $this->assertSame('@@GENERATED@@user2@doc.pdf@@', $result[1]);
    }

    public function testGeneratedFilePatternMatchesPdfExtension(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.pdf@@'));
    }

    public function testGeneratedFilePatternMatchesPngExtension(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.png@@'));
    }

    public function testGeneratedFilePatternDoesNotMatchTxtExtension(): void
    {
        $this->assertSame(0, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.txt@@'));
    }

    public function testMixedContentWithImagesAndPdfs(): void
    {
        $content = 'Image: @@GENERATED@@u1@a.png@@ and PDF: @@GENERATED@@u2@b.pdf@@ and another image: @@GENERATED@@u3@c.jpg@@';
        $result = $this->extension->processGeneratedFiles($content);

        $this->assertStringContainsString('/files/serve/', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }
}
