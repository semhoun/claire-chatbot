<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ChatHistoryRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Services\Markdown;
use App\Services\Pdf\DocumentResourceValidator;
use App\Services\Pdf\RestrictedAssetFetcher;
use App\Services\PdfGeneratorService;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdfResourceSecurityTest extends TestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4b'
        . 'AAAAD0lEQVQImWNgYGBgYGAAAAAHAAHV3tbYAAAAAElFTkSuQmCC';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pdf-security-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public static function forbiddenDocuments(): iterable
    {
        $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<rect width="10" height="10" fill="red"/></svg>');
        foreach (['<script></script>', '<style></style>', '<style media="print"></style>',
            '<!--mpdf <style></style> mpdf-->'] as $removed) {
            yield 'reconstructed SVG ' . $removed => ['<img src="da' . $removed
                . 'ta:image/svg+xml;base64,' . $svg . '">', 'html'];
            yield 'reconstructed variable ' . $removed => ['<input type="image" src="v' . $removed
                . 'ar:untrusted">', 'html'];
        }
        yield 'quoted SVG background' => ['<style>body { background-image: "url(data:image/svg+xml;base64,'
            . $svg . ')"; }</style><p>Report</p>', 'html'];
        yield 'mPDF style prefix' => ['<stylex>body { background-image: "url(data:image/svg+xml;base64,'
            . $svg . ')"; }</style><p>Report</p>', 'html'];
        yield 'quoted SVG inline background' => ['<p style="background-image: &quot;url(data:image/svg+xml;base64,'
            . $svg . ')&quot;">Report</p>', 'html'];
        yield 'quoted SVG list image' => ['<style>li { list-style-image: "url(data:image/svg+xml;base64,'
            . $svg . ')"; }</style><ul><li>Report</li></ul>', 'html'];
        yield 'image declaration inside CSS string' => ['<style>body { content: "x; background-image:'
            . ' url(data:image/svg+xml;base64,' . $svg . ');" }</style><p>Report</p>', 'html'];
        yield 'watermark image source' => ['<watermarkimage src="data:image/svg+xml;base64,' . $svg . '"/>', 'html'];
        yield 'UTF-7 image source' => ['<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-7"></head>'
            . '<img src="+AGQ-ata:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==">', 'html'];
        foreach ([
            'https://example.org/image.png', 'http://localhost/image.png',
            'http://169.254.169.254/latest/meta-data/', '//example.org/image.png',
            '/etc/passwd', 'file:///etc/passwd', 'php://filter/resource=/etc/passwd',
            'phar:///tmp/image.png', '../image.png', 'relative.png',
            'data:image/png;base64,' . self::PNG, 'prefix-var:test',
            'd&#97;ta:image/png;base64,' . self::PNG,
            'd&amp;#97;ta:image/png;base64,' . self::PNG,
            '%64ata:image/png;base64,' . self::PNG,
        ] as $source) {
            yield $source => ['<IMG SRC="' . $source . '">', 'html'];
        }
        foreach ([
            '<base href="https://example.org/">',
            '<link rel="stylesheet" href="/etc/passwd">',
            '<style>@import "https://example.org/a.css";</style>',
            '<style>@media print { @import "nested.css"; }</style>',
            '<style>@im/**/port "a.css";</style>',
            '<style>@\\69mport "a.css";</style>',
            '<style>body { background: u\\72l(https://example.org/a.png); }</style>',
            '<style>body { background: u/**/rl(data:image/png;base64,' . self::PNG . '); }</style>',
            '<div style="background:url(&quot;data:image/png;base64,' . self::PNG . '&quot;)">x</div>',
            '<style>body { background: url(data:image/png;base64,' . self::PNG . '); }</style>',
            '<svg><image href="/etc/passwd"/></svg>',
            '<!-- <svg></svg> -->',
            '<annotation content="note" file="/etc/passwd"/>',
            '<annotation content="a > b" file="/etc/passwd"/>',
            '<!--mpdf <img src="data:image/png;base64,' . self::PNG . '"> mpdf-->',
            '<!--MPDF <style>@import "a.css";</style> MPDF-->',
            '<!--mpdf <htmlpageheader name="x"><img src="/etc/passwd"></htmlpageheader>'
                . '<sethtmlpageheader name="x" value="on" show-this-page="1"/> mpdf-->',
            '<img src="var:test" src="/etc/passwd">',
            '<img src="/etc/passwd" src="data:image/png;base64,' . self::PNG . '">',
            '<img src="data:image/png;base64,' . self::PNG . '" broken=">',
            '<i<!--mpdfmpdf-->mg src="data:image/png;base64,' . self::PNG . '">',
            '<st<script></script>yle>body {background:url(data:image/png;base64,' . self::PNG . ')}</style>',
            '<style>body {background:"url(\'data:image/png;base64,' . self::PNG . '\')"}</style>',
        ] as $index => $html) {
            yield 'construction ' . $index => [$html, 'html'];
        }
        yield 'markdown remote' => ['![image](http://localhost/test.png)', 'markdown'];
        yield 'markdown local' => ['![image](/etc/passwd)', 'markdown'];
        yield 'markdown reference image' => ["![image][id]\n\n[id]: http://localhost/test.png", 'markdown'];
    }

    #[DataProvider('forbiddenDocuments')]
    public function testForbiddenResourcesNeverProduceStoredPdf(string $content, string $format): void
    {
        $service = $this->service();
        try {
            $service->generatePdf($this->createStub(SessionInterface::class), 'thread', [
                'content' => $content, 'format' => $format, 'pageSize' => 'A4',
            ]);
            self::fail('Resource policy must reject the document.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('PDF resource denied', $exception->getMessage());
            self::assertStringNotContainsString('/etc/passwd', $exception->getMessage());
        }
        self::assertSame([], glob($this->directory . '/claire-pdf-*'));
    }

    public function testFetcherChecksBothExactPathsBeforeAnyIo(): void
    {
        $allowed = $this->directory . '/allowed.png';
        $other = $this->directory . '/other.png';
        file_put_contents($allowed, base64_decode(self::PNG, true));
        file_put_contents($other, 'not authorized');
        $fetcher = new RestrictedAssetFetcher([$allowed]);
        self::assertSame(base64_decode(self::PNG, true), $fetcher->fetchDataFromPath($allowed, $allowed));
        foreach ([[$other, null], [$allowed, $other], [$other, $allowed],
            ['file://' . $allowed, null], [$this->directory . '/./allowed.png', null],
            ['pdfprobe://image', null], [$allowed, 'pdfprobe://image'],
        ] as [$path, $original]) {
            try {
                $fetcher->fetchDataFromPath($path, $original);
                self::fail('Exact allowlist must reject the path.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('PDF resource denied', $exception->getMessage());
            }
        }
    }

    public function testNoStreamIsOpenedForUnapprovedResources(): void
    {
        PdfProbeStream::$accesses = 0;
        stream_wrapper_register('pdfprobe', PdfProbeStream::class);
        try {
            $this->testForbiddenResourcesNeverProduceStoredPdf('<img src="pdfprobe://secret">', 'html');
            $this->testFetcherChecksBothExactPathsBeforeAnyIo();
            self::assertSame(0, PdfProbeStream::$accesses);
        } finally {
            stream_wrapper_unregister('pdfprobe');
        }
    }

    public function testPresentationAndMpdfTagsAreNotRewritten(): void
    {
        $html = '<style>.cover { color:#903050; background:#f0e0d0; border:2px solid #903050;'
            . ' padding:12mm; font-family:dejavuserif; } .box { margin:5mm; }</style>'
            . '<!--mpdf <htmlpageheader name="brand"><b>Report header</b></htmlpageheader>'
            . '<htmlpagefooter name="pages">Page {PAGENO}</htmlpagefooter>'
            . '<sethtmlpageheader name="brand" value="on" show-this-page="1"/>'
            . '<sethtmlpagefooter name="pages" value="on"/> mpdf-->'
            . '<div class="cover"><h1 style="font-size:30pt;color:#903050">Cover</h1></div>'
            . '<pagebreak/><div class="box">Second page <a href="https://example.org/">source</a></div>';
        $service = $this->service();
        $pdf = new \ReflectionMethod($service, 'renderPdf')->invoke($service, $html, 'A4', 'portrait', []);
        $document = new \Smalot\PdfParser\Parser()->parseContent($pdf);
        self::assertCount(2, $document->getPages());
        foreach ($document->getPages() as $page) {
            self::assertStringContainsString('Report header', $page->getText());
            self::assertStringContainsString('Page', $page->getText());
        }
        self::assertStringContainsString('Second page', $document->getPages()[1]->getText());
        new DocumentResourceValidator()->validate('<style>p:before {content:"url(example)";}</style>');
    }

    public function testTextAttributesAreNotTreatedAsImageSources(): void
    {
        $html = '<p title="Source data: annuel">Report</p>'
            . '<a href="https://example.org/metadata:guide">Guide</a>'
            . '<bookmark content="Data: r&#233;sultats" level="0"/>'
            . '<p title="var: label" style="color:#123456">Results</p>'
            . '<style>p:before {content:"Source data: annuel";}</style>';
        $service = $this->service();
        $pdf = new \ReflectionMethod($service, 'renderPdf')->invoke($service, $html, 'A4', 'portrait', []);
        $document = new \Smalot\PdfParser\Parser()->parseContent($pdf);
        self::assertStringContainsString('Report', $document->getText());
        $links = $document->getObjectsByType('Annot');
        self::assertCount(1, $links);
        self::assertSame('https://example.org/metadata:guide', array_values($links)[0]->get('A')->get('URI')->getContent());
    }

    public function testMetaCharsetCannotTranscodeUtf8Input(): void
    {
        $html = '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-7"></head>'
            . '<p>+AGQ-ata: Report</p>';
        $service = $this->service();
        $pdf = new \ReflectionMethod($service, 'renderPdf')->invoke($service, $html, 'A4', 'portrait', []);
        $document = new \Smalot\PdfParser\Parser()->parseContent($pdf);
        self::assertStringContainsString('+AGQ-ata: Report', $document->getText());
    }

    public function testNonUtf8InputIsRejectedBeforeRendering(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF content must be valid UTF-8.');
        $this->service()->generatePdf($this->createStub(SessionInterface::class), 'thread', [
            'content' => "<p>Invalid \xff</p>", 'format' => 'html', 'pageSize' => 'A4',
        ]);
    }

    public function testPreparedRendersUseDistinctFilesEvenWithIdenticalDisplayNames(): void
    {
        $service = $this->service([base64_decode(self::PNG, true)]);
        $resolve = new \ReflectionMethod($service, 'resolveGeneratedImages');
        $html = '@@GENERATED@@user@first.png@@ @@GENERATED@@user@second.png@@';
        [$firstHtml, $first] = $resolve->invoke($service, $html, new User());
        [$secondHtml, $second] = $resolve->invoke($service, $html, new User());
        self::assertCount(4, array_unique([...$first, ...$second]));
        foreach ([...$first, ...$second] as $path) {
            self::assertFileExists($path);
            self::assertSame(base64_decode(self::PNG, true), file_get_contents($path));
        }
        new \ReflectionMethod($service, 'cleanupTempFiles')->invoke($service, $first);
        foreach ($second as $path) {
            self::assertFileExists($path);
        }
        $pdf = new \ReflectionMethod($service, 'renderPdf')->invoke($service, $secondHtml, 'A4', 'portrait', [], $second);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertNotSame($firstHtml, $secondHtml);
    }

    public static function resolutionFailures(): iterable
    {
        yield 'missing second record' => [null];
        yield 'SVG disguised as PNG' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'];
        yield 'invalid raster' => ['not a PNG'];
    }

    #[DataProvider('resolutionFailures')]
    public function testPartialResolutionCleansEarlierImages(?string $second): void
    {
        $service = $this->service([base64_decode(self::PNG, true), $second]);
        try {
            $service->generatePdf($this->createStub(SessionInterface::class), 'thread', [
                'content' => '@@GENERATED@@user@first.png@@ @@GENERATED@@user@second.png@@',
                'format' => 'html', 'pageSize' => 'A4',
            ]);
            self::fail('Second image should fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                $second === null ? 'Image ID' : 'supported raster images',
                $exception->getMessage(),
            );
            self::assertSame([], glob($this->directory . '/claire-pdf-*'));
        }
    }

    public function testSuccessfulGenerationCleansImagesBeforeStorage(): void
    {
        $service = $this->service([base64_decode(self::PNG, true)], store: true);
        $id = $service->generatePdf($this->createStub(SessionInterface::class), 'thread', [
            'content' => '@@GENERATED@@user@first.png@@', 'format' => 'html', 'pageSize' => 'A4',
        ]);
        self::assertStringContainsString('@@GENERATED@@', $id);
        self::assertSame([], glob($this->directory . '/claire-pdf-*'));
    }

    public function testRenderFailureCleansPreparedImages(): void
    {
        $service = $this->service([base64_decode(self::PNG, true)]);
        try {
            $service->generatePdf($this->createStub(SessionInterface::class), 'thread', [
                'content' => '@@GENERATED@@user@first.png@@ <img src="/etc/passwd">',
                'format' => 'html', 'pageSize' => 'A4',
            ]);
            self::fail('Unauthorized image should fail rendering.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('PDF resource denied', $exception->getMessage());
            self::assertSame([], glob($this->directory . '/claire-pdf-*'));
        }
    }

    /** @param list<string|null> $images */
    private function service(array $images = [], bool $store = false): PdfGeneratorService
    {
        $user = new User();
        $user->setId('user');
        $history = new ChatHistory();
        $history->setUser($user);
        $users = $this->createStub(UserRepository::class);
        $users->method('getCurrentUser')->willReturn($user);
        $histories = $this->createStub(ChatHistoryRepository::class);
        $histories->method('getCurrentUserChatHistory')->willReturn($history);
        $files = $this->createStub(FileRepository::class);
        $index = 0;
        $files->method('findOneBy')->willReturnCallback(function (array $criteria) use ($images, &$index): ?File {
            self::assertInstanceOf(User::class, $criteria['user']);
            $data = $images[min($index++, count($images) - 1)] ?? null;
            if ($data === null) {
                return null;
            }
            $file = $this->createStub(File::class);
            $file->method('fileType')->willReturn(File::FILE_TYPE_IMAGE);
            $file->method('getFilename')->willReturn('identical.png');
            $file->method('getFilePath')->willReturn($data);

            return $file;
        });
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnMap([
            [User::class, $users], [ChatHistory::class, $histories], [File::class, $files],
        ]);
        $manager->expects($store ? self::once() : self::never())->method('persist');
        $manager->expects($store ? self::once() : self::never())->method('flush');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($store ? self::once() : self::never())->method('write')->willReturnCallback(
            function (string $path, string $pdf): void {
                self::assertStringStartsWith('generated/user/', $path);
                self::assertSame([], glob($this->directory . '/claire-pdf-*'));
                $document = new \Smalot\PdfParser\Parser()->parseContent($pdf);
                self::assertNotEmpty($document->getObjectsByType('XObject', 'Image'));
            },
        );
        $filesystem->method('read')->willReturnCallback(static fn (string $path): string => $path);

        return new PdfGeneratorService(
            new Settings(['tools' => ['pdf' => ['tempDir' => $this->directory]]]),
            $filesystem, $manager, new Markdown(),
        );
    }
}

final class PdfProbeStream
{
    public mixed $context;
    public static int $accesses = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        ++self::$accesses;

        return false;
    }

    public function url_stat(string $path, int $flags): false
    {
        ++self::$accesses;

        return false;
    }
}
