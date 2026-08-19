<?php

declare(strict_types=1);

namespace App\Test\Unit\Twig;

use App\Services\Settings;
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
        $html = $this->twig->fetch('app.twig', [
            'base_url' => 'https://claire.test',
            'config' => $this->frontendConfig(),
        ]);

        self::assertStringContainsString('id="claire-vue-app"', $html);
        self::assertStringContainsString('thread-42', $html);
        self::assertStringContainsString(
            'src="https://claire.test/build/js/app.js"',
            $html
        );
    }

    public function testEmbedIsAStandaloneBootstrapFragment(): void
    {
        $config = $this->frontendConfig();
        $config['mode'] = 'embed';

        $html = $this->twig->fetch('embed.twig', [
            'base_url' => 'https://claire.test',
            'config' => $config,
        ]);

        self::assertStringContainsString('class="claire-embed-bootstrap"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    public function testEmptyListsUseSvgIcons(): void
    {
        $history = $this->twig->fetch('partials/history_list.twig', [
            'histories' => [],
            'base_url' => '',
        ]);
        $files = $this->twig->fetch('partials/files_list.twig', [
            'files' => [],
            'base_url' => '',
            'accepted_ext' => '.txt',
        ]);

        self::assertStringContainsString('<svg', $history);
        self::assertStringContainsString('<svg', $files);
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

    /** @return array<string, mixed> */
    private function frontendConfig(): array
    {
        return [
            'mode' => 'normal',
            'acceptedExt' => '.txt',
            'threadId' => 'thread-42',
            'sessionId' => 'sess-abc123',
            'brainInfo' => [
                'name' => 'Claire',
                'description' => 'Assistant',
                'avatar' => '/avatar.png',
                'css' => '',
                'cssInline' => '',
            ],
            'currentBrain' => 'claire',
            'brains' => [],
            'comfyuiEnabled' => false,
            'workflows' => [],
            'currentWorkflow' => '',
            'longTermMemoryEnabled' => false,
            'layoutMode' => 'full',
            'user' => null,
            'refreshBeforeExpire' => 120,
            'refreshMinInterval' => 30,
        ];
    }
}
