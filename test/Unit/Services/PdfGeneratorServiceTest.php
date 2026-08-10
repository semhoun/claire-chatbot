<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Entity\File;
use App\Entity\User;
use App\Services\Auth;
use App\Services\PdfGeneratorService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use PHPUnit\Framework\TestCase;

final class PdfGeneratorServiceTest extends TestCase
{
    private string $imagesTempDir;

    protected function setUp(): void
    {
        $this->imagesTempDir = sys_get_temp_dir() . '/images';
    }

    protected function tearDown(): void
    {
        // Clean up temp files after each test
        if (is_dir($this->imagesTempDir)) {
            $files = glob($this->imagesTempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    public function testResolveGeneratedImagesReplacesImageTokensWithTempFiles(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $chatHistoryRepository = $this->createMock(\App\Repository\ChatHistoryRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $file = $this->createMock(File::class);
        $file->method('getFilePath')->willReturn('generated/user-123/image-uuid.png');
        $file->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);
        $file->method('getFilename')->willReturn('image.png');
        $fileRepository->method('findOneBy')->willReturn($file);
        $entityManager->method('getRepository')->with(File::class)->willReturn($fileRepository);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        $user = new User();
        $user->setId('user-123');
        $imageData = 'fake-image-binary-data';

        $filesystem->expects($this->once())
            ->method('read')
            ->with('generated/user-123/image-uuid.png')
            ->willReturn($imageData);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $html = '<p>Here is an image: @@GENERATED@@user-123@image-uuid.png@@</p>';
        [$result, $tempFiles] = $method->invoke($service, $html, $user);

        // Should have one temp file
        $this->assertCount(1, $tempFiles);
        $this->assertFileExists($tempFiles[0]);

        // Result should contain img tag with temp file path
        $this->assertStringContainsString('<img src="' . $tempFiles[0] . '"', $result);
        $this->assertStringContainsString('style="max-width:100%;height:auto;">', $result);

        // Clean up
        @unlink($tempFiles[0]);
    }

    public function testResolveGeneratedImagesThrowsExceptionForDifferentUser(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $fileRepository->method('findOneBy')->willReturn(null);
        $entityManager->method('getRepository')->with(File::class)->willReturn($fileRepository);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        // Filesystem should never be called for different user's image
        $filesystem->expects($this->never())->method('read');

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $user = new User();
        $user->setId('user-123');
        $html = '<p>@@GENERATED@@other-user@image-uuid.png@@</p>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image ID @@GENERATED@@other-user@image-uuid.png@@ not found');

        $method->invoke($service, $html, $user);
    }

    public function testResolveGeneratedImagesLeavesPdfTokensUnchanged(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $file = $this->createMock(File::class);
        $file->method('fileType')->willReturn(File::FILE_TYPE_PDF);
        $fileRepository->method('findOneBy')->willReturn($file);
        $entityManager->method('getRepository')->with(File::class)->willReturn($fileRepository);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        // Filesystem should not be called for PDF tokens
        $filesystem->expects($this->never())->method('read');

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $user = new User();
        $user->setId('user-123');
        $html = '<p>See PDF: @@GENERATED@@user-123@document-uuid.pdf@@</p>';
        [$result, $tempFiles] = $method->invoke($service, $html, $user);

        // PDF token should be left as-is
        $this->assertSame($html, $result);
        $this->assertCount(0, $tempFiles);
    }

    public function testResolveGeneratedImagesThrowsExceptionWhenFileNotFound(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $fileRepository->method('findOneBy')->willReturn(null);
        $entityManager->method('getRepository')->with(File::class)->willReturn($fileRepository);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $user = new User();
        $user->setId('user-123');
        $html = '<p>@@GENERATED@@user-123@missing-uuid.png@@</p>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image ID @@GENERATED@@user-123@missing-uuid.png@@ not found');

        $method->invoke($service, $html, $user);
    }

    public function testResolveGeneratedImagesHandlesMultipleTokens(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $chatHistoryRepository = $this->createMock(\App\Repository\ChatHistoryRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $file1 = $this->createMock(File::class);
        $file1->method('getFilePath')->willReturn('generated/user-123/image1-uuid.png');
        $file1->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);
        $file1->method('getFilename')->willReturn('image1.png');
        $file2 = $this->createMock(File::class);
        $file2->method('getFilePath')->willReturn('generated/user-123/image2-uuid.jpg');
        $file2->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);
        $file2->method('getFilename')->willReturn('image2.jpg');

        $fileRepository->method('findOneBy')->willReturnCallback(function (array $criteria) use ($file1, $file2): ?File {
            if ($criteria['fileId'] === '@@GENERATED@@user-123@image1-uuid.png@@') {
                return $file1;
            }
            if ($criteria['fileId'] === '@@GENERATED@@user-123@image2-uuid.jpg@@') {
                return $file2;
            }

            return null;
        });
        $entityManager->method('getRepository')->with(File::class)->willReturn($fileRepository);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        $user = new User();
        $user->setId('user-123');

        $filesystem->expects($this->exactly(2))
            ->method('read')
            ->willReturnCallback(function (string $path) {
                return match ($path) {
                    'generated/user-123/image1-uuid.png' => 'image1-data',
                    'generated/user-123/image2-uuid.jpg' => 'image2-data',
                    default => throw new \RuntimeException('Unexpected path: ' . $path),
                };
            });

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $html = '<p>First: @@GENERATED@@user-123@image1-uuid.png@@</p><p>Second: @@GENERATED@@user-123@image2-uuid.jpg@@</p>';
        [$result, $tempFiles] = $method->invoke($service, $html, $user);

        // Should have two temp files
        $this->assertCount(2, $tempFiles);

        // Both should exist and have correct extensions
        $this->assertFileExists($tempFiles[0]);
        $this->assertFileExists($tempFiles[1]);
        $this->assertStringEndsWith('.png', $tempFiles[0]);
        $this->assertStringEndsWith('.jpg', $tempFiles[1]);

        // Clean up
        foreach ($tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testResolveGeneratedImagesHandlesNoTokens(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $chatHistoryRepository = $this->createMock(\App\Repository\ChatHistoryRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        // Filesystem should never be called when there are no tokens
        $filesystem->expects($this->never())->method('read');

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $user = new User();
        $user->setId('user-123');
        $html = '<p>Just plain HTML without any image tokens.</p>';
        [$result, $tempFiles] = $method->invoke($service, $html, $user);

        $this->assertSame($html, $result);
        $this->assertCount(0, $tempFiles);
    }

    public function testCleanupTempFilesRemovesFiles(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => '/tmp',
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $chatHistoryRepository = $this->createMock(\App\Repository\ChatHistoryRepository::class);
        $markdown = $this->createMock(\App\Services\Markdown::class);

        $service = new PdfGeneratorService(
            $settings,
            $filesystem,
            $entityManager,
            $markdown,
        );

        // Create test temp files
        $tempDir = sys_get_temp_dir() . '/claire_test_' . uniqid();
        @mkdir($tempDir, 0o750, true);
        $file1 = $tempDir . '/test1.png';
        $file2 = $tempDir . '/test2.jpg';
        file_put_contents($file1, 'test data 1');
        file_put_contents($file2, 'test data 2');

        $this->assertFileExists($file1);
        $this->assertFileExists($file2);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('cleanupTempFiles');

        $method->invoke($service, [$file1, $file2, '/nonexistent/file.txt']);

        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);

        // Clean up
        @rmdir($tempDir);
    }
}
