<?php

declare(strict_types=1);

namespace App\Test\Unit\Twig;

use App\Services\Settings;
use App\Renderer\VueShell;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

final class WebTemplatesTest extends TestCase
{
    private Twig $twig;

    protected function setUp(): void
    {
        $this->twig = Twig::create(Settings::getAppRoot() . '/tmpl');
    }

    public function testAppMountsVueWithEscapedBootstrapData(): void
    {
        $html = new VueShell()->document([
            'page' => 'app', 'baseUrl' => 'https://claire.test',
            'title' => '</script><script>alert(1)</script>',
        ]);

        self::assertStringContainsString('id="claire-vue-app"', $html);
        self::assertStringNotContainsString('</script><script>', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString(
            'src="https://claire.test/build/js/app.js"',
            $html
        );
    }

    public function testOnlyTelegramTemplatesRemain(): void
    {
        foreach (['app', 'embed', 'welcome', 'auth_callback', 'error', 'public_base'] as $name) {
            self::assertFalse($this->twig->getEnvironment()->getLoader()->exists($name . '.twig'));
        }
    }

    public function testMigratedPartialsAreNoLongerTwigTemplates(): void
    {
        foreach (['history_list', 'files_list', 'rag_list', 'rag_segments', 'telegram_config'] as $name) {
            self::assertFalse($this->twig->getEnvironment()->getLoader()->exists('partials/' . $name . '.twig'));
        }
    }

    public function testTelegramWebAppIsRenderedByTwig(): void
    {
        $html = $this->twig->fetch('telegram/webapp.twig', [
            'base_url' => 'https://claire.test',
            'brains' => [],
            'workflows' => [],
            'comfyui_enabled' => false,
        ]);

        self::assertStringContainsString(
            'const baseUrl = "https:\/\/claire.test";',
            $html
        );
        self::assertStringNotContainsString('Workflow ComfyUI', $html);
    }

}
