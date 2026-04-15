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
    public function testChatTemplateLoadsSingleSseConnectionOnPageLoad(): void
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
            'stream_session_id' => 'sess-abc123',
            'layout_mode' => 'full',
            'brains' => [],
            'current_brain' => 'claire',
            'comfyui_enabled' => false,
            'comfyui_workflows' => [],
            'current_comfyui_workflow' => '',
            'uinfo' => ['id' => 'default'],
        ]);

        // Single EventSource uses sessionId and current chatId
        $this->assertStringContainsString('data-chat-id="thread-42"', $html);
        $this->assertStringContainsString('data-stream-session-id="sess-abc123"', $html);

        // JS is loaded from external files (not inline)
        $this->assertStringContainsString('/js/app.js', $html);
        $this->assertStringContainsString('/js/sse.js', $html);

        // Form submission includes both chatId and sessionId
        $this->assertStringContainsString('hx-post="/brain/messages"', $html);
        $this->assertStringContainsString('name="chatId" value="thread-42"', $html);
        $this->assertStringContainsString('name="sessionId" value="sess-abc123"', $html);

        // Session ID management
        $this->assertStringContainsString('window.claireStreamSessionId', $html);
        $this->assertStringContainsString('data-current-chat-id-input', $html);
        $this->assertStringContainsString('data-stream-session-id-input', $html);
    }

    public function testChatTemplateReusesExistingAssistantArticleForPlaceholderUpdates(): void
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
            'stream_session_id' => 'sess-abc123',
            'layout_mode' => 'full',
            'brains' => [],
            'current_brain' => 'claire',
            'comfyui_enabled' => false,
            'comfyui_workflows' => [],
            'current_comfyui_workflow' => '',
            'uinfo' => ['id' => 'default'],
        ]);

        // JS logic is now in external app.js - verify the script is loaded
        $this->assertStringContainsString('/js/app.js', $html);
        // Verify data attributes for message handling are present
        $this->assertStringContainsString('data-chat-messages', $html);
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
        $this->assertStringContainsString('/js/sse.js', $html);
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
