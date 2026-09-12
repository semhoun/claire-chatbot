<?php

declare(strict_types=1);

namespace App\Test\Unit\Rendering;

use App\Entity\File;
use App\Renderer\ChatHtmlRenderer;
use App\Services\Markdown;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ChatHtmlRendererTest extends TestCase
{
    public function testAllRenderingEntryPointsScopeFileLookupToUser(): void
    {
        $marker = '@@GENERATED@@private-file@@';
        $file = $this->createStub(File::class);
        $file->method('getFileId')->willReturn('private-file');
        $file->method('fileType')->willReturn(File::FILE_TYPE_PDF);
        $file->method('getFilename')->willReturn('confidential.pdf');
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(6))->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($marker, $file): ?File {
                self::assertSame($marker, $criteria['fileId']);
                self::assertArrayHasKey('user', $criteria);
                return $criteria['user'] === 'owner' ? $file : null;
            });
        $renderer = $this->renderer($repository);

        foreach (['markdown', 'message', 'messages'] as $method) {
            $input = match ($method) {
                'markdown' => $marker,
                'message' => ['message' => $marker],
                'messages' => [['message' => $marker]],
            };
            $owned = $renderer->$method($input, 'owner');
            $foreign = $renderer->$method($input, 'other-user');

            self::assertStringContainsString('confidential.pdf', $owned);
            self::assertStringContainsString('/files/serve/private-file', $owned);
            self::assertStringNotContainsString('confidential.pdf', $foreign);
            self::assertStringNotContainsString('/files/serve/', $foreign);
            self::assertStringNotContainsString('claire-generated-file', $foreign);
            self::assertStringContainsString($marker, $foreign);
        }
    }

    public function testStreamingPlaceholdersAndEmptyMessagesNeverQueryFiles(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('findOneBy');
        $renderer = $this->renderer($repository);

        $html = $renderer->markdown('@@GENERATED@@private-file@@', 'owner', true);

        self::assertStringContainsString('claire-generated-image-placeholder', $html);
        self::assertStringNotContainsString('/files/serve/', $html);
        self::assertSame($renderer->messages([], 'owner'), $renderer->messages(null, 'owner'));
        self::assertStringContainsString('claire-typing-indicator', $renderer->messages([], 'owner'));
    }

    private function renderer(EntityRepository $repository): ChatHtmlRenderer
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new ChatHtmlRenderer(new Markdown(), new GeneratedFileProcessor(
            new Settings(['base_url' => 'http://localhost']),
            $entityManager,
        ));
    }
}
