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

        // Native EventSource handles snapshots and incremental updates
        $this->assertStringContainsString('new EventSource', $html);
        $this->assertStringContainsString('/brain/stream?sessionId=', $html);
        $this->assertStringContainsString('&chatId=', $html);
        $this->assertStringContainsString('window.chatEventSource', $html);
        $this->assertStringContainsString("eventType === 'message.assistant.start'", $html);
        $this->assertStringContainsString("addEventListener('chat.snapshot'", $html);
        $this->assertStringContainsString("addEventListener('message.assistant.delta'", $html);
        $this->assertStringContainsString('messageArticleId', $html);
        $this->assertStringNotContainsString('hx-indicator="#typingIndicator"', $html);
        $this->assertStringContainsString('class="typing-indicator" aria-hidden="true"', $html);

        // JSON message handling for incremental updates
        $this->assertStringContainsString('JSON.parse(event.data)', $html);
        $this->assertStringContainsString('update.html', $html);
        $this->assertStringContainsString('element.innerHTML = htmlContent', $html);

        // Form submission includes both chatId and sessionId
        $this->assertStringContainsString('hx-post="/brain/messages"', $html);
        $this->assertStringContainsString('name="chatId" value="thread-42"', $html);
        $this->assertStringContainsString('name="sessionId" value="sess-abc123"', $html);

        // Per-tab session ID stored in sessionStorage
        $this->assertStringContainsString('sessionStorage', $html);
        $this->assertStringContainsString('claireStreamSessionId', $html);
        $this->assertStringContainsString('serverSessionId || window.sessionStorage.getItem(STORAGE_KEY)', $html);
        $this->assertStringContainsString('window.claireStreamSessionId', $html);

        // Reconnection handling
        $this->assertStringContainsString('setTimeout', $html);
        $this->assertStringContainsString('initChatEventSource(sessionId)', $html);
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

        $this->assertStringContainsString('const existingTarget = document.getElementById(incomingMessageId);', $html);
        $this->assertStringContainsString('document.getElementById(update.messageArticleId)', $html);
        $this->assertStringContainsString('existingArticle.outerHTML = htmlContent;', $html);
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
