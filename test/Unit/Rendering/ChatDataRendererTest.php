<?php

declare(strict_types=1);

namespace App\Test\Unit\Rendering;

use App\Brain\ChatHistory\MessageFormatter;
use App\Entity\ChatHistory;
use App\Entity\File;
use App\Entity\User;
use App\Renderer\ChatDataRenderer;
use App\Services\Rendering\GeneratedFileProcessor;
use App\Services\Settings;
use App\Services\SseEventFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use NeuronAI\Chat\Messages\AssistantMessage;
use PHPUnit\Framework\TestCase;

final class ChatDataRendererTest extends TestCase
{
    public function testRealGeneratedImageSurvivesHistoryAndStreamingSerialization(): void
    {
        $user = new User();
        $user->setId('owner');
        $history = new ChatHistory();
        $history->setUser($user);
        $file = new File();
        $file->setGeneratedFileData($history, 'Generated image', 'png');
        $id = $file->getFileId();
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(6))->method('findOneBy')
            ->with(['fileId' => $id, 'user' => 'owner'])->willReturn($file);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $renderer = new ChatDataRenderer(new GeneratedFileProcessor(
            new Settings(['base_url' => 'https://claire.test']), $manager,
        ));
        foreach ([$id, '![Image](' . $id . ')', '<img src="' . $id . '">'] as $text) {
            $messages = new MessageFormatter([new AssistantMessage($text)])->format();
            $snapshot = $renderer->messages($messages, 'owner')[0];
            $stream = $renderer->content($text, 'owner', true);
            self::assertSame($snapshot['files'], $stream['files']);
            self::assertSame($text, $snapshot['message']);
            self::assertSame([
                'id' => $id, 'name' => 'Generated image.png', 'type' => 'image',
                'url' => 'https://claire.test/files/serve/' . rawurlencode($id),
            ], $stream['files'][0]);
            $event = new SseEventFormatter()->formatJsonEvent($stream, eventName: 'chat.assistant.update');
            $data = json_decode(explode("data: ", $event, 2)[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($stream, $data);
        }
    }

    public function testEveryEntryPointResolvesOnlyOwnedFilesWithoutRenderingHtml(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $file = $this->createStub(File::class);
        $file->method('getFileId')->willReturn('@@GENERATED@@file@@');
        $file->method('getFilename')->willReturn('<img onerror=alert(1)>.png');
        $file->method('fileType')->willReturn('image');
        $repository->expects(self::exactly(4))->method('findOneBy')
            ->with(['fileId' => '@@GENERATED@@file@@', 'user' => 'owner'])->willReturn($file);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $renderer = new ChatDataRenderer(new GeneratedFileProcessor(new Settings(['base_url' => 'https://claire.test']), $manager));
        $text = '**Hello** @@GENERATED@@file@@';
        foreach ([$renderer->content($text, 'owner'), $renderer->content($text, 'owner', true),
            $renderer->message(['message' => $text], 'owner'), $renderer->messages([['message' => $text]], 'owner')[0]] as $data) {
            self::assertSame($text, $data['message']);
            self::assertSame('image', $data['files'][0]['type']);
            self::assertSame('https://claire.test/files/serve/%40%40GENERATED%40%40file%40%40', $data['files'][0]['url']);
            self::assertArrayNotHasKey('html', $data);
        }
        self::assertSame([], $renderer->messages([], 'owner'));
    }

    public function testMissingOrForeignFilesRevealNoMetadataAndPendingFilesHaveNoUrl(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')
            ->with(['fileId' => '@@GENERATED@@file@@', 'user' => 'other'])->willReturn(null);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $processor = new GeneratedFileProcessor(new Settings([]), $manager);
        self::assertSame([], $processor->resolve('@@GENERATED@@file@@', 'other'));
        $pending = $processor->resolve('@@GENERATED@@file@@', 'other', true);
        self::assertSame(null, $pending[0]['url']);
        self::assertSame('pending', $pending[0]['type']);
        self::assertSame([], $processor->resolve('@@GENERATED@@file@@', ''));
        self::assertSame([], $processor->resolve('No files', 'other'));
    }
}
