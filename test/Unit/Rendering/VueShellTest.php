<?php

declare(strict_types=1);

namespace App\Test\Unit\Rendering;

use App\Renderer\VueShell;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VueShellTest extends TestCase
{
    private string $appRoot;

    protected function setUp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/claire-shell-' . bin2hex(random_bytes(8));
        mkdir($this->appRoot . '/frontend', 0777, true);
        mkdir($this->appRoot . '/public/build/.vite', 0777, true);
        file_put_contents(
            $this->appRoot . '/frontend/shell.html',
            '<head>__APP_CSS__</head><script src="__BASE_URL__/build/js/app.js"></script>'
            . '<script type="application/json">__PAGE_DATA__</script>'
        );
    }

    protected function tearDown(): void
    {
        $manifest = $this->appRoot . '/public/build/.vite/manifest.json';
        if (is_file($manifest)) {
            unlink($manifest);
        }
        unlink($this->appRoot . '/frontend/shell.html');
        foreach (['/frontend', '/public/build/.vite', '/public/build', '/public', ''] as $directory) {
            rmdir($this->appRoot . $directory);
        }
    }

    public function testMissingManifestRendersWithoutStylesheetFallback(): void
    {
        $html = new VueShell($this->appRoot)->document();

        self::assertStringContainsString('<head></head>', $html);
        self::assertStringNotContainsString('app.css', $html);
        self::assertStringNotContainsString('__APP_CSS__', $html);
    }

    public function testManifestStylesheetsPreserveOrderAndEscapeUrls(): void
    {
        $this->manifest(json_encode([
            'frontend/main.ts' => ['css' => ['assets/app-abc123.css', 'assets/shared-def456.css']],
            'other.ts' => ['css' => ['assets/unrelated.css']],
        ], JSON_THROW_ON_ERROR));

        $html = new VueShell($this->appRoot)->document([
            'baseUrl' => 'https://claire.test/sub?x="&y=1',
            'title' => '</script><script>alert(1)</script>',
        ]);

        self::assertStringContainsString(
            '<head><link rel="stylesheet" href="https://claire.test/sub?x=&quot;&amp;y=1'
            . '/build/assets/app-abc123.css">' . "\n"
            . '<link rel="stylesheet" href="https://claire.test/sub?x=&quot;&amp;y=1'
            . '/build/assets/shared-def456.css"></head>',
            $html
        );
        self::assertStringContainsString('src="https://claire.test/sub?x=&quot;&amp;y=1/build/js/app.js"', $html);
        self::assertStringNotContainsString('unrelated.css', $html);
        self::assertStringNotContainsString('</script><script>', $html);
    }

    public function testBaseUrlTrailingSlashIsNotDuplicated(): void
    {
        $this->manifest('{"frontend/main.ts":{"css":["assets/app-abc.css"]}}');

        self::assertStringContainsString(
            'href="/sub/build/assets/app-abc.css"',
            new VueShell($this->appRoot)->document(['baseUrl' => '/sub/'])
        );
    }

    #[DataProvider('invalidManifests')]
    public function testInvalidManifestFailsExplicitly(string $manifest, string $exception): void
    {
        $this->manifest($manifest);
        $this->expectException($exception);

        new VueShell($this->appRoot)->document();
    }

    public static function invalidManifests(): iterable
    {
        yield 'malformed JSON' => ['{', JsonException::class];
        yield 'invalid root' => ['null', RuntimeException::class];
        yield 'missing entry' => ['{}', RuntimeException::class];
        yield 'missing CSS' => ['{"frontend/main.ts":{}}', RuntimeException::class];
        yield 'invalid CSS list' => ['{"frontend/main.ts":{"css":"app.css"}}', RuntimeException::class];
        yield 'invalid CSS path' => ['{"frontend/main.ts":{"css":[null]}}', RuntimeException::class];
        yield 'empty CSS path' => ['{"frontend/main.ts":{"css":[""]}}', RuntimeException::class];
    }

    private function manifest(string $contents): void
    {
        file_put_contents($this->appRoot . '/public/build/.vite/manifest.json', $contents);
    }
}
