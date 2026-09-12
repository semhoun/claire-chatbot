<?php

declare(strict_types=1);

namespace App\Test\Unit\Rendering;

use App\Renderer\HtmlErrorRenderer;
use App\Renderer\JsonErrorRenderer;
use App\Renderer\VueShell;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HtmlErrorRendererTest extends TestCase
{
    public function testErrorShellEscapesDebugDataAndHidesProductionDetails(): void
    {
        $renderer = new HtmlErrorRenderer(new VueShell(), new NullLogger());
        $exception = new \RuntimeException('</script><script>alert(1)</script>private-details');
        $production = $renderer($exception, false);
        self::assertStringContainsString('id="claire-vue-app"', $production);
        self::assertStringNotContainsString('private-details', $production);
        $debug = $renderer($exception, true);
        self::assertStringNotContainsString('</script><script>', $debug);
        self::assertStringContainsString('private-details', $debug);
    }

    public function testNotFoundHasVueShellWhileApiErrorsRemainJson(): void
    {
        $exception = new HttpNotFoundException(new ServerRequestFactory()->createServerRequest('GET', '/missing'));
        $html = new HtmlErrorRenderer(new VueShell(), new NullLogger())($exception, false);
        self::assertStringContainsString('"code":404', $html);
        self::assertStringNotContainsString('<h1', $html);
        $json = new JsonErrorRenderer(new NullLogger())($exception, false);
        self::assertSame(404, json_decode($json, true, flags: JSON_THROW_ON_ERROR)['code']);
        self::assertStringNotContainsString('<html', $json);
    }
}
