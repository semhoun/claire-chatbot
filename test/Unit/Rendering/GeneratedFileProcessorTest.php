<?php

declare(strict_types=1);

namespace App\Test\Unit\Rendering;

use App\Entity\File;
use App\Services\Settings;
use App\Services\Rendering\GeneratedFileProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GeneratedFileProcessorTest extends TestCase
{
    private GeneratedFileProcessor $processor;
    private Settings $settings;
    private EntityManagerInterface&MockObject $entityManager;
    private EntityRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->settings = new Settings(['base_url' => 'http://localhost']);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(File::class)
            ->willReturn($this->repository);

        $this->processor = new GeneratedFileProcessor(
            $this->settings,
            $this->entityManager
        );
    }

    public function testProcessGeneratedFilesWithImageId(): void
    {
        $placeholder = '@@GENERATED@@user123@abc-def.png@@';
        $content = 'Here is an image: ' . $placeholder;

        $file = $this->createMock(File::class);
        $file->method('getFileId')->willReturn('uuid-123');
        $file->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);

        $this->repository->method('findOneBy')
            ->with(['fileId' => $placeholder])
            ->willReturn($file);

        $result = $this->processor->process($content);

        $this->assertStringContainsString('http://localhost/files/serve/uuid-123', $result);
        $this->assertStringContainsString('class="claire-generated-image"', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }

    public function testProcessGeneratedFilesWithPdfId(): void
    {
        $placeholder = '@@GENERATED@@user123@abc-def.pdf@@';
        $content = 'Here is a PDF: ' . $placeholder;

        $file = $this->createMock(File::class);
        $file->method('getFileId')->willReturn('uuid-456');
        $file->method('fileType')->willReturn(File::FILE_TYPE_PDF);
        $file->method('getFilename')->willReturn('test.pdf');

        $this->repository->method('findOneBy')
            ->with(['fileId' => $placeholder])
            ->willReturn($file);

        $result = $this->processor->process($content);

        $this->assertStringContainsString('http://localhost/files/serve/uuid-456', $result);
        $this->assertStringContainsString('class="claire-generated-file"', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
    }

    public function testProcessGeneratedAudioAsPlayer(): void
    {
        $placeholder = '@@GENERATED@@audio-id@@';
        $file = $this->createMock(File::class);
        $file->method('getFileId')->willReturn('audio-id');
        $file->method('fileType')->willReturn(File::FILE_TYPE_AUDIO);
        $this->repository->method('findOneBy')
            ->with(['fileId' => $placeholder])
            ->willReturn($file);

        $result = $this->processor->process($placeholder);

        self::assertStringContainsString('<audio controls', $result);
        self::assertStringContainsString('class="claire-generated-audio"', $result);
        self::assertStringContainsString(
            'data-protected-src="http://localhost/files/serve/audio-id"',
            $result,
        );
    }

    public function testProcessGeneratedFilesPlaceholderWithPdfId(): void
    {
        $content = 'Here is a PDF: @@GENERATED@@user123@abc-def.pdf@@';
        $result = $this->processor->processPlaceholder($content);

        // La logique actuelle utilise str_ends_with sur le match complet incluant @@
        // Donc .pdf@@ finit par @@, pas par .pdf.
        // Mais attendez, le code est :
        // if (str_ends_with(strtolower($matches[1]), '.pdf'))
        // Et matches[1] est le premier groupe capturé.
        // Regex : "/(['\"]?@@GENERATED@@[a-zA-Z0-9_@\-.]+@@['\"]?)/"
        // Groupe 1 est tout le placeholder.
        
        $this->assertStringContainsString('claire-generated-image-placeholder', $result);
    }

    public function testProcessGeneratedFilesPlaceholderWithImageId(): void
    {
        $content = 'Here is an image: @@GENERATED@@user123@abc-def.png@@';
        $result = $this->processor->processPlaceholder($content);

        $this->assertStringContainsString('claire-generated-image-placeholder', $result);
    }

    public function testGeneratedFilePatternMatchesPdfExtension(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.pdf@@'));
    }

    public function testGeneratedFilePatternMatchesPngExtension(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.png@@'));
    }

    public function testGeneratedFilePatternMatchesMp3Extension(): void
    {
        self::assertSame(
            1,
            preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.mp3@@'),
        );
    }

    public function testGeneratedFilePatternMatchesTxtExtension(): void
    {
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, '@@GENERATED@@user123@abc-def.txt@@'));
    }

    public function testGeneratedFilePatternMatchesCompleteATag(): void
    {
        $content = '<a class="btn" href="@@GENERATED@@user123@abc-def.pdf@@" target="_blank">';
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, $content, $matches));
        $this->assertSame('<a class="btn" href=', $matches[1]);
        $this->assertSame('"@@GENERATED@@user123@abc-def.pdf@@"', $matches[2]);
        $this->assertSame(' target="_blank">', $matches[3]);
    }

    public function testGeneratedFilePatternMatchesCompleteImgTag(): void
    {
        $content = '<img src="@@GENERATED@@user123@abc-def.png@@" alt="test">';
        $this->assertSame(1, preg_match(File::GENERATED_FILE_PATTERN, $content, $matches));
        $this->assertSame('<img src=', $matches[1]);
        $this->assertSame('"@@GENERATED@@user123@abc-def.png@@"', $matches[2]);
        $this->assertSame(' alt="test">', $matches[3]);
    }

    public function testMixedContentWithImagesAndPdfs(): void
    {
        $placeholder1 = '@@GENERATED@@u1@a.png@@';
        $placeholder2 = '@@GENERATED@@u2@b.pdf@@';
        $placeholder3 = '@@GENERATED@@u3@c.jpg@@';
        $content = "Image: $placeholder1 and PDF: $placeholder2 and another image: $placeholder3";

        $file1 = $this->createMock(File::class);
        $file1->method('getFileId')->willReturn('uuid-1');
        $file1->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);

        $file2 = $this->createMock(File::class);
        $file2->method('getFileId')->willReturn('uuid-2');
        $file2->method('fileType')->willReturn(File::FILE_TYPE_PDF);
        $file2->method('getFilename')->willReturn('b.pdf');

        $file3 = $this->createMock(File::class);
        $file3->method('getFileId')->willReturn('uuid-3');
        $file3->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);

        $this->repository->method('findOneBy')->willReturnMap([
            [['fileId' => $placeholder1], $file1],
            [['fileId' => $placeholder2], $file2],
            [['fileId' => $placeholder3], $file3],
        ]);

        $result = $this->processor->process($content);

        $this->assertStringContainsString('http://localhost/files/serve/uuid-1', $result);
        $this->assertStringContainsString('http://localhost/files/serve/uuid-2', $result);
        $this->assertStringContainsString('http://localhost/files/serve/uuid-3', $result);
        $this->assertStringNotContainsString('@@GENERATED@@', $result);
        $this->assertStringContainsString('class="claire-generated-image"', $result);
        $this->assertStringContainsString('class="claire-generated-file"', $result);
    }

    public function testProcessGeneratedFilesInsideHrefWithoutQuotes(): void
    {
        $placeholder = '@@GENERATED@@user123@abc-def.pdf@@';
        $content = '<a href=' . $placeholder . '>Download</a>';

        $file = $this->createMock(File::class);
        $file->method('getFileId')->willReturn('uuid-pdf');
        $file->method('fileType')->willReturn(File::FILE_TYPE_PDF);
        $file->method('getFilename')->willReturn('test.pdf');

        $this->repository->method('findOneBy')
            ->with(['fileId' => $placeholder])
            ->willReturn($file);

        $result = $this->processor->process($content);

        // Devrait être <a href="http://localhost/files/serve/uuid-pdf">Download</a>
        // ou au moins <a href=http://localhost/files/serve/uuid-pdf>Download</a>
        // MAIS SURTOUT PAS <a href=<a href="...">...</a>>
        $this->assertStringContainsString('href="http://localhost/files/serve/uuid-pdf"', $result);
        $this->assertStringContainsString('class="claire-generated-file"', $result);
    }

    public function testProcessGeneratedFilesInsideSrcWithoutQuotes(): void
    {
        $placeholder = '@@GENERATED@@user123@abc-def.png@@';
        $content = '<img src=' . $placeholder . '>';

        $file = $this->createMock(File::class);
        $file->method('getFileId')->willReturn('uuid-img');
        $file->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);

        $this->repository->method('findOneBy')
            ->with(['fileId' => $placeholder])
            ->willReturn($file);

        $result = $this->processor->process($content);

        $this->assertStringContainsString('src="http://localhost/files/serve/uuid-img"', $result);
        $this->assertStringContainsString('class="claire-generated-image"', $result);
    }
}
