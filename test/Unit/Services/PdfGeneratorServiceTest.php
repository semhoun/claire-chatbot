<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\Tools\PdfGeneratorTool;
use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Services\Auth;
use App\Services\PdfGeneratorService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

final class PdfGeneratorServiceTest extends TestCase
{
    private string $imagesTempDir;

    protected function setUp(): void
    {
        $this->imagesTempDir = sys_get_temp_dir() . '/claire-pdf-test-' . bin2hex(random_bytes(8));
        mkdir($this->imagesTempDir, 0o700);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->imagesTempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->imagesTempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->imagesTempDir);
        }
    }

    public static function pdfInputs(): array
    {
        return [
            'service missing filename' => [false, []],
            'service null filename' => [false, ['filename' => null]],
            'service blank filename' => [false, ['filename' => '  ']],
            'neuron omitted options' => [true, []],
            'neuron padded filename' => [true, ['filename' => ' Report '], 'Report.pdf'],
            'neuron null options' => [true, array_fill_keys([
                'format', 'filename', 'page_size', 'orientation',
                'margin_top', 'margin_bottom', 'margin_left', 'margin_right',
            ], null)],
            'neuron explicit options' => [true, [
                'format' => 'markdown', 'filename' => 'Report', 'page_size' => 'A5',
                'orientation' => 'landscape', 'margin_top' => 0, 'margin_bottom' => 6,
                'margin_left' => 7, 'margin_right' => 8,
            ], 'Report.pdf'],
        ];
    }

    #[DataProvider('pdfInputs')]
    public function testRealPdfWithIsolatedStorageAndNeuronOptions(
        bool $viaTool,
        array $inputs,
        string $expectedFilename = 'document.pdf',
    ): void {
        $settings = new Settings(['tools' => ['pdf' => [
            'enabled' => true, 'tempDir' => $this->imagesTempDir,
            'defaultFormat' => 'html', 'defaultPageSize' => 'A4',
        ]]]);
        $user = new User();
        $user->setId('pdf-test');
        $history = new ChatHistory();
        $history->setUser($user);
        $session = $this->createStub(SessionInterface::class);
        $users = $this->createStub(\App\Repository\UserRepository::class);
        $users->method('getCurrentUser')->willReturn($user);
        $histories = $this->createStub(\App\Repository\ChatHistoryRepository::class);
        $histories->method('getCurrentUserChatHistory')->willReturn($history);
        $manager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnMap([[User::class, $users], [ChatHistory::class, $histories]]);
        $saved = null;
        $manager->expects(self::once())->method('persist')->willReturnCallback(static function (File $file) use (&$saved): void {
            $saved = $file;
        });
        $manager->expects(self::once())->method('flush');
        $pdf = '';
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('write')->willReturnCallback(
            static function (string $path, string $content) use (&$pdf): void {
                self::assertStringStartsWith('generated/pdf-test/', $path);
                self::assertStringStartsWith('%PDF-', $content);
                $pdf = $content;
            },
        );
        $markdown = $this->createMock(\App\Services\Markdown::class);
        $isMarkdown = ($inputs['format'] ?? 'html') === 'markdown';
        $markdown->expects($isMarkdown ? self::once() : self::never())->method('convert')
            ->with('Diagnostic')->willReturn('<p>Diagnostic</p>');
        $service = new PdfGeneratorService($settings, $filesystem, $manager, $markdown);
        if ($viaTool) {
            $tool = new PdfGeneratorTool($service, $settings, $session, 'test-thread');
            $tool->setInputs(['content' => 'Diagnostic', ...$inputs]);
            $tool->execute();
            $result = json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('success', $result['status']);
            $id = $result['id'];
            self::assertSame($saved->getFilename(), $result['name']);
        } else {
            $id = $service->generatePdf($session, 'test-thread', ['content' => 'Diagnostic', ...$inputs]);
        }
        self::assertSame($saved->getFileId(), $id);
        self::assertSame($expectedFilename, $saved->getFilename());
        self::assertSame(strlen($pdf), $saved->getSizeBytes());
        self::assertSame([
            'format' => $inputs['format'] ?? 'html', 'pageSize' => $inputs['page_size'] ?? 'A4',
            'orientation' => $isMarkdown ? 'L' : 'P',
        ], $saved->getMetadata());
        // Compare page drawing commands to direct rendering, including all four margins (and zero).
        $expected = new \ReflectionMethod($service, 'renderPdf')->invoke(
            $service, $isMarkdown ? '<p>Diagnostic</p>' : 'Diagnostic',
            $inputs['page_size'] ?? 'A4', $inputs['orientation'] ?? 'portrait',
            ['top' => $inputs['margin_top'] ?? 15, 'bottom' => $inputs['margin_bottom'] ?? 15,
                'left' => $inputs['margin_left'] ?? 15, 'right' => $inputs['margin_right'] ?? 15],
        );
        self::assertSame(1, preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $actualStream));
        self::assertSame(1, preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $expected, $expectedStream));
        self::assertSame($expectedStream[1], $actualStream[1]);
    }

    public function testResolveGeneratedImagesReplacesImageTokensWithTempFiles(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createStub(\App\Repository\FileRepository::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

        $file = $this->createStub(File::class);
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
        $imageData = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4b'
                . 'AAAAD0lEQVQImWNgYGBgYGAAAAAHAAHV3tbYAAAAAElFTkSuQmCC',
            true,
        );

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
        $document = $this->renderDocument($result, tempFiles: $tempFiles);
        $images = $document->getObjectsByType('XObject', 'Image');
        self::assertNotEmpty($images);
        self::assertSame(2, (int) array_values($images)[0]->get('Width')->getContent());
        self::assertSame(1, (int) array_values($images)[0]->get('Height')->getContent());

        // Clean up
        @unlink($tempFiles[0]);
    }

    public function testDefaultStylesRenderSemanticMarkdownAndSafeLinks(): void
    {
        $html = new \App\Services\Markdown()->convert("# R\u{00e9}sum\u{00e9}\n\n" . <<<'MD'
            Texte **important** et [source](https://example.org/report).

            ## Section

            - Premier point
            - Second point

            > Une citation.

            ```php
            $total = 42;
            ```

            [unsafe](javascript:alert%281%29)
            MD);
        $document = $this->renderDocument($html);
        self::assertCount(1, $document->getPages());
        $page = $document->getPages()[0];
        self::assertStringContainsString("R\u{00e9}sum\u{00e9}", $page->getText());
        $runs = $page->getDataTm();
        self::assertEqualsWithDelta(24, (float) $runs[0][3], 0.1);
        self::assertStringContainsString('DejaVuSans', $page->getFont($runs[0][2])->getDetails()['BaseFont']);
        $sizes = array_map(static fn (array $run): float => (float) $run[3], $runs);
        self::assertContains(16.0, $sizes);
        self::assertContains(10.5, $sizes);
        self::assertContains(9.0, $sizes);
        self::assertStringContainsString('$total = 42;', $page->getText());
        $links = $document->getObjectsByType('Annot');
        self::assertCount(1, $links);
        self::assertSame('https://example.org/report', array_values($links)[0]->get('A')->get('URI')->getContent());
    }

    public function testMissingSymbolsUseEmbeddedFallbackWithoutLosingUnicodeOrCustomStyles(): void
    {
        $symbols = "\u{1F431}\u{2B50}\u{2728}\u{1F43E}\u{1F4D6}";
        $document = $this->renderDocument(
            '<style>body { font-family: dejavuserif; font-size: 18pt; color: #990000; }</style>'
            . "<p>R\u{00E9}sum\u{00E9}</p><p>$symbols</p>"
            . '<pagebreak /><p style="font-family:dejavusans">&#x1F431;</p><h2>&#x1F4D6; &#x2728;</h2>',
        );
        self::assertCount(2, $document->getPages());
        $page = $document->getPages()[0];
        self::assertStringContainsString("R\u{00E9}sum\u{00E9}", $page->getText());
        $runs = $page->getDataTm();
        self::assertStringContainsString('DejaVuSerif', $page->getFont($runs[0][2])->getDetails()['BaseFont']);
        self::assertEqualsWithDelta(18, (float) $runs[0][3], 0.1);
        foreach ($document->getPages() as $page) {
            $fallbackRuns = array_filter($page->getDataTm(), static fn (array $run): bool =>
                str_contains($page->getFont($run[2])->getDetails()['BaseFont'], 'Symbola'));
            self::assertNotEmpty($fallbackRuns);
        }
        // Smalot decodes surrogate pairs separately; check the PDF's UTF-16 mapping directly.
        $unicodeMaps = '';
        $dejavuMap = '';
        foreach ($document->getObjectsByType('Font') as $font) {
            if ($font->has('ToUnicode')) {
                $unicodeMaps .= $font->get('ToUnicode')->getContent();
                if (str_contains($font->getDetails()['BaseFont'], 'DejaVuSans')) {
                    $dejavuMap .= $font->get('ToUnicode')->getContent();
                }
                if (str_contains($font->getDetails()['BaseFont'], 'Symbola')) {
                    self::assertNotEmpty($font->get('FontDescriptor')->get('FontFile2')->getContent());
                }
            }
        }
        self::assertStringContainsString('<D83DDC31>', $dejavuMap);
        foreach (mb_str_split($symbols) as $symbol) {
            $utf16 = strtoupper(bin2hex(mb_convert_encoding($symbol, 'UTF-16BE', 'UTF-8')));
            self::assertStringContainsString('<' . $utf16 . '>', $unicodeMaps);
        }
    }

    public function testLongTableWrapsWithoutTinyTextAndRepeatsHeadings(): void
    {
        $html = '<h1>Report</h1><table><thead><tr><th>Reference</th><th>Status</th></tr></thead><tbody>';
        for ($row = 1; $row <= 60; ++$row) {
            $html .= '<tr><td>' . str_repeat('LONGREFERENCE', 10) . '</td><td>Row ' . $row . '</td></tr>';
        }
        $document = $this->renderDocument($html . '</tbody></table>');
        self::assertGreaterThan(1, count($document->getPages()));
        foreach ($document->getPages() as $page) {
            self::assertStringContainsString('Reference', $page->getText());
            self::assertStringContainsString('Status', preg_replace('/\s+/', '', $page->getText()));
            foreach ($page->getDataTm() as $run) {
                self::assertGreaterThanOrEqual(9.4, (float) $run[3]);
            }
        }
        self::assertStringContainsString('Row60', preg_replace('/\s+/', '', $document->getText()));
    }

    public function testHeadingStaysWithFollowingParagraphAtPageBoundary(): void
    {
        $document = $this->renderDocument(
            '<div style="height:250mm">Opening</div><h2>Next section</h2><p>Following paragraph</p>',
        );
        self::assertCount(2, $document->getPages());
        self::assertStringNotContainsString('Next section', $document->getPages()[0]->getText());
        self::assertStringContainsString('Next section', $document->getPages()[1]->getText());
        self::assertStringContainsString('Following paragraph', $document->getPages()[1]->getText());
    }

    public static function pageOptions(): array
    {
        return [
            'zero margins' => ['A4', 'portrait', ['top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0], 210, 297],
            'landscape' => ['A5', 'landscape', ['top' => 7, 'bottom' => 9, 'left' => 11, 'right' => 13], 210, 148],
            'letter' => ['Letter', 'portrait', [], 215.9, 279.4],
        ];
    }

    #[DataProvider('pageOptions')]
    public function testExplicitPageOptionsAndCustomStylesRemainEffective(
        string $pageSize,
        string $orientation,
        array $margins,
        float $width,
        float $height,
    ): void {
        $html = '<html><head><style>body { font-family: dejavuserif; font-size: 18pt; }'
            . 'h1 { font-size: 20pt; color: #990000; margin: 0; }'
            . 'table { font-size: 14pt; }</style></head><body>'
            . '<p>Custom body</p><h1>Custom heading</h1><p style="font-size:22pt">Inline style</p>'
            . '<table><tr><td>Custom table</td></tr></table>'
            . '<p style="page-break-before:always">Second page</p></body></html>';
        $document = $this->renderDocument($html, $pageSize, $orientation, $margins);
        self::assertCount(2, $document->getPages());
        $page = $document->getPages()[0];
        $box = $page->getDetails()['MediaBox'];
        self::assertEqualsWithDelta($width * 72 / 25.4, $box[2], 0.1);
        self::assertEqualsWithDelta($height * 72 / 25.4, $box[3], 0.1);
        $runs = $page->getDataTm();
        self::assertEqualsWithDelta(($margins['left'] ?? 15) * 72 / 25.4, (float) $runs[0][0][4], 0.1);
        self::assertEqualsWithDelta(18, (float) $runs[0][3], 0.1);
        self::assertStringContainsString('DejaVuSerif', $page->getFont($runs[0][2])->getDetails()['BaseFont']);
        $sizes = array_map(static fn (array $run): float => (float) $run[3], $runs);
        self::assertContains(20.0, $sizes);
        self::assertContains(22.0, $sizes);
        self::assertContains(14.0, $sizes);
        self::assertStringContainsString('Second page', $document->getPages()[1]->getText());
        $zeroTop = $this->renderDocument($html, $pageSize, $orientation, [...$margins, 'top' => 0]);
        $zeroY = (float) $zeroTop->getPages()[0]->getDataTm()[0][0][5];
        self::assertEqualsWithDelta(
            ($margins['top'] ?? 15) * 72 / 25.4,
            $zeroY - (float) $runs[0][0][5],
            0.1,
        );
    }

    public static function cssPageSizes(): array
    {
        return [
            'named A4 regression' => ['A4', 'A4', 'portrait', 210, 297],
            'CSS box preserves paper parameter' => ['a5', 'A4', 'portrait', 210, 297],
            'Letter' => ['Letter', 'Letter', 'portrait', 215.9, 279.4],
            'A3' => ['A3', 'A3', 'portrait', 297, 420],
            'A5 landscape' => ['A5 landscape', 'A5', 'landscape', 210, 148],
            'explicit dimensions' => ['210mm 297mm', 'A4', 'portrait', 210, 297],
            'auto follows parameter' => ['auto', 'A5', 'landscape', 210, 148],
        ];
    }

    #[DataProvider('cssPageSizes')]
    public function testCssPageSizeProducesOneSanePage(
        string $size, string $pageSize, string $orientation, float $width, float $height,
    ): void {
        $document = $this->renderDocument(
            '<style>@page { size: ' . $size . '; margin:20mm 18mm 22mm 18mm; }</style><p>ABC DEF</p>',
            $pageSize, $orientation,
        );
        self::assertCount(1, $document->getPages());
        $page = $document->getPages()[0];
        self::assertStringContainsString('ABC DEF', $page->getText());
        $box = $page->getDetails()['MediaBox'];
        self::assertEqualsWithDelta($width * 72 / 25.4, $box[2], 0.1);
        self::assertEqualsWithDelta($height * 72 / 25.4, $box[3], 0.1);
    }

    public static function invalidPageGeometry(): array
    {
        return [
            'parameter margins' => ['<p>ABC DEF</p>', ['left' => 111, 'right' => 100]],
            'CSS margins' => ['<style>@page { size:A4; margin:150mm 10mm; }</style><p>ABC DEF</p>', []],
            'zero size' => ['<style>@page { size:0mm 0mm; margin:0; }</style><p>ABC DEF</p>', []],
            'named page' => ['<style>@page broken { margin:150mm 10mm; }</style>'
                . '<p>First</p><pagebreak page-selector="broken" /><p>ABC DEF</p>', []],
            'named first page' => ['<style>@page broken {size:auto;}'
                . '@page broken :first { margin-top:300mm; }</style>'
                . '<p>First</p><pagebreak page-selector="broken" /><p>ABC DEF</p>', []],
            'first page' => ['<style>@page :first { margin:150mm 10mm; }</style><p>ABC DEF</p>', []],
            'left page' => ['<style>@page {size:auto;} @page :left { margin:150mm 10mm; }</style>'
                . '<p>First</p><pagebreak /><p>ABC DEF</p>', []],
            'pagebreak margins' => ['<p>First</p><pagebreak margin-top="300mm" /><p>ABC DEF</p>', []],
        ];
    }

    #[DataProvider('invalidPageGeometry')]
    public function testInvalidEffectivePageGeometryFails(string $html, array $margins): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid PDF printable geometry');
        $this->renderDocument($html, margins: $margins);
    }

    public function testNamedPageSizeAndPseudoPageStylesRemainSupported(): void
    {
        $document = $this->renderDocument('<style>@page {size:A4;} @page chapter {size:Letter;sheet-size:Letter;}'
            . '@page chapter:left {margin:20mm;} @page :first {margin:0;}</style>'
            . '<p>First</p><pagebreak page-selector="chapter" /><p>Second</p>');
        self::assertCount(2, $document->getPages());
        self::assertEqualsWithDelta(612, $document->getPages()[1]->getDetails()['MediaBox'][2], 0.1);
    }

    public static function renderingFailures(): array
    {
        return [
            'cap' => ['<p>First</p><pagebreak /><p>Second</p><pagebreak /><p>Third</p>',
                'PDF page limit of 2 exceeded during rendering'],
            'geometry' => ['<style>@page {size:A4;margin:150mm;}</style><p>ABC DEF</p>',
                'Invalid PDF printable geometry'],
        ];
    }

    #[DataProvider('renderingFailures')]
    public function testRenderingFailureCleansImagesWithoutSaving(string $html, string $error): void
    {
        $user = new User();
        $history = new ChatHistory();
        $users = $this->createStub(\App\Repository\UserRepository::class);
        $users->method('getCurrentUser')->willReturn($user);
        $histories = $this->createStub(\App\Repository\ChatHistoryRepository::class);
        $histories->method('getCurrentUserChatHistory')->willReturn($history);
        $image = $this->createStub(File::class);
        $image->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);
        $image->method('getFilePath')->willReturn('image.png');
        $files = $this->createStub(\App\Repository\FileRepository::class);
        $files->method('findOneBy')->willReturn($image);
        $manager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnMap([
            [User::class, $users], [ChatHistory::class, $histories], [File::class, $files],
        ]);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('write');
        $filesystem->expects(self::once())->method('read')->willReturn(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4b'
            . 'AAAAD0lEQVQImWNgYGBgYGAAAAAHAAHV3tbYAAAAAElFTkSuQmCC', true,
        ));
        $service = new PdfGeneratorService(
            new Settings(['tools' => ['pdf' => [
                'tempDir' => $this->imagesTempDir, 'maxPages' => 2,
                'defaultFormat' => 'html', 'defaultPageSize' => 'A4',
            ]]]), $filesystem, $manager, new \App\Services\Markdown(),
        );
        try {
            $service->generatePdf($this->createStub(SessionInterface::class), 'thread', [
                'content' => '@@GENERATED@@image@@' . $html,
            ]);
            self::fail('Rendering should fail without saving.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($error, $exception->getMessage());
        }
        self::assertSame([], glob($this->imagesTempDir . '/claire-pdf-*'));
    }

    private function renderDocument(
        string $html,
        string $pageSize = 'A4',
        string $orientation = 'portrait',
        array $margins = [],
        array $tempFiles = [],
    ): Document {
        $service = new PdfGeneratorService(
            new Settings(['tools' => ['pdf' => ['tempDir' => $this->imagesTempDir]]]),
            $this->createStub(Filesystem::class),
            $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            new \App\Services\Markdown(),
        );
        $pdf = new \ReflectionMethod($service, 'renderPdf')->invoke($service, $html, $pageSize, $orientation, $margins, $tempFiles);
        $config = new Config();
        $config->setDataTmFontInfoHasToBeIncluded(true);

        return new Parser([], $config)->parseContent($pdf);
    }

    public function testResolveGeneratedImagesThrowsExceptionForDifferentUser(): void
    {
        $settings = new Settings([
            'tools' => [
                'pdf' => [
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createStub(\App\Repository\FileRepository::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

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
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createStub(\App\Repository\FileRepository::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

        $file = $this->createStub(File::class);
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
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createStub(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createStub(\App\Repository\FileRepository::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

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
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $fileRepository = $this->createStub(\App\Repository\FileRepository::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

        $file1 = $this->createStub(File::class);
        $file1->method('getFilePath')->willReturn('generated/user-123/image1-uuid.png');
        $file1->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);
        $file1->method('getFilename')->willReturn('image1.png');
        $file2 = $this->createStub(File::class);
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
                    'generated/user-123/image1-uuid.png', 'generated/user-123/image2-uuid.jpg' => base64_decode(
                        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4b'
                        . 'AAAAD0lEQVQImWNgYGBgYGAAAAAHAAHV3tbYAAAAAElFTkSuQmCC', true),
                    default => throw new \RuntimeException('Unexpected path: ' . $path),
                };
            });

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('resolveGeneratedImages');


        $html = '<p>First: @@GENERATED@@user-123@image1-uuid.png@@</p><p>Second: @@GENERATED@@user-123@image2-uuid.jpg@@</p>';
        [$result, $tempFiles] = $method->invoke($service, $html, $user);

        // Should have two temp files
        $this->assertCount(2, $tempFiles);

        // Display names and extensions do not determine temporary paths.
        $this->assertFileExists($tempFiles[0]);
        $this->assertFileExists($tempFiles[1]);
        $this->assertNotSame($tempFiles[0], $tempFiles[1]);

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
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

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
                    'tempDir' => $this->imagesTempDir,
                ],
            ],
        ]);

        $filesystem = $this->createStub(Filesystem::class);
        $entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $markdown = $this->createStub(\App\Services\Markdown::class);

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
