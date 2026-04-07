<?php

declare(strict_types=1);

namespace App\Test\Unit\Twig;

use App\Services\Markdown;
use App\Services\Twig\GeneratedImageExtension;
use App\Services\Twig\TimestampExtension;
use OneToMany\Twig\FilesizeExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Loader\FilesystemLoader;

final class ChatTemplateSseTest extends TestCase
{
    public function testChatTemplateLoadsHybridSseConnectionOnPageLoad(): void
    {
        $twig = $this->createTwig();

        $html = $twig->render('chat.twig', [
            'base_url' => '',
            'brain_info' => [
                'avatar' => '/avatar.png',
                'name' => 'Claire',
                'description' => 'Assistant',
                'css' => null,
                'css_inline' => null,
            ],
            'messages' => [],
            'settings' => new class() {
                public function get(string $key): string
                {
                    return match ($key) {
                        'files.upload.acceptedExt' => '.txt',
                        default => '',
                    };
                }
            },
            'current_chat_id' => 'thread-42',
            'layout_mode' => 'full',
            'brains' => [],
            'current_brain' => 'claire',
            'comfyui_enabled' => false,
            'comfyui_workflows' => [],
            'current_comfyui_workflow' => '',
            'uinfo' => ['id' => 'default'],
        ]);

        // HTMX SSE for snapshots
        $this->assertStringContainsString('hx-ext="sse"', $html);
        $this->assertStringContainsString('sse-connect="/brain/stream?chatId=thread-42"', $html);
        $this->assertStringContainsString('sse-swap="chat.snapshot"', $html);
        $this->assertStringContainsString('data-chat-id="thread-42"', $html);

        // Native EventSource for incremental updates
        $this->assertStringContainsString('new EventSource', $html);
        $this->assertStringContainsString('/brain/stream?chatId=', $html);
        $this->assertStringContainsString('mode=incremental', $html);
        $this->assertStringContainsString('window.chatIncrementalEventSource', $html);

        // JSON message handling for incremental updates
        $this->assertStringContainsString('JSON.parse(event.data)', $html);
        $this->assertStringContainsString('update.html', $html);
        $this->assertStringContainsString('element.innerHTML = htmlContent', $html);

        // Form submission
        $this->assertStringContainsString('hx-post="/brain/messages"', $html);
        $this->assertStringContainsString('name="chatId" value="thread-42"', $html);

        // Reconnection handling
        $this->assertStringContainsString('setTimeout', $html);
    }

    public function testLayoutLoadsHtmxSseExtension(): void
    {
        $twig = $this->createTwig();

        $html = $twig->render('layout.twig', [
            'base_url' => '',
            'brain_info' => [
                'css' => null,
                'css_inline' => null,
            ],
            'settings' => new class() {
                public function get(string $key): string
                {
                    return match ($key) {
                        'files.upload.acceptedExt' => '.txt',
                        default => '',
                    };
                }
            },
            'layout_mode' => 'full',
            'brains' => [],
            'current_brain' => 'claire',
            'comfyui_enabled' => false,
            'comfyui_workflows' => [],
            'current_comfyui_workflow' => '',
            'uinfo' => ['id' => 'default'],
        ]);

        // Should contain HTMX SSE extension for hybrid SSE
        $this->assertStringContainsString('htmx-ext-sse', $html);
        $this->assertStringContainsString('sse.js', $html);
    }

    private function createTwig(): Environment
    {
        $twig = new Environment(new FilesystemLoader('/home/nathanael/www/claire/tmpl'));
        $twig->addExtension(new MarkdownExtension());
        $twig->addExtension(new FilesizeExtension());
        $twig->addExtension(new GeneratedImageExtension());
        $twig->addExtension(new TimestampExtension());
        $twig->addRuntimeLoader(new class() implements \Twig\RuntimeLoader\RuntimeLoaderInterface {
            public function load($class): ?MarkdownRuntime
            {
                if ($class === MarkdownRuntime::class) {
                    return new MarkdownRuntime(new Markdown());
                }

                return null;
            }
        });

        return $twig;
    }
}
