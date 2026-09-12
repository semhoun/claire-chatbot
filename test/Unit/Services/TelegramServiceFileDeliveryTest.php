<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Services\Auth;
use App\Services\Session\TelegramSession;
use App\Services\TelegramMarkdown;
use App\Services\TelegramService;
use Doctrine\ORM\EntityManager;
use League\Flysystem\Filesystem;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class TelegramServiceFileDeliveryTest extends TestCase
{
    public static function deliveries(): iterable
    {
        foreach (self::fileTypes() as [$mime, $method]) {
            foreach ([false, true] as $duplicate) {
                foreach ([false, true] as $preparationFailure) {
                    yield [$mime, $method, $duplicate, $preparationFailure];
                }
            }
        }
    }

    public static function fileTypes(): iterable
    {
        yield ['image/png', 'sendPhoto'];
        yield ['audio/mpeg', 'sendAudio'];
        yield ['application/pdf', 'sendDocument'];
    }

    #[DataProvider('deliveries')]
    public function testRetrySkipsConfirmedOccurrence(
        string $mime,
        string $method,
        bool $duplicate,
        bool $preparationFailure,
    ): void {
        $first = '@@GENERATED@@first@@';
        $second = $duplicate ? $first : '@@GENERATED@@second@@';
        $response = '<img src="' . $first . '"><img src="' . $second . '">Caption';
        $states = [];
        $checkpoint = $this->checkpoint($states);
        $prefix = 'file:' . hash('sha256', $response) . ':';
        $firstKey = $prefix . '0:' . $first;
        $secondKey = $prefix . '1:' . $second;
        $preparations = [];
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::exactly(3))->method('fileExists')->willReturnCallback(
            static function (string $path) use (&$preparations, &$states, $preparationFailure): bool {
                self::assertContains('uncertain', $states);
                $preparations[] = $path;
                return ! ($preparationFailure && count($preparations) === 2);
            },
        );
        $filesystem->method('read')->willReturn('file bytes');
        $requests = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('post')->willReturn(new ApiResponse(200, '{"ok":true,"result":true}'));
        $transport->expects(self::exactly($preparationFailure ? 2 : 3))->method('postWithFiles')
            ->willReturnCallback(static function (string $url, array $data, array $files) use (
                &$requests, $method, $preparationFailure,
            ): ApiResponse {
                self::assertStringEndsWith('/' . $method, $url);
                $requests[] = [reset($files)->filename(), $data['caption'] ?? null];
                if (! $preparationFailure && count($requests) === 2) {
                    return new ApiResponse(200, '{"ok":false,"error_code":429,"description":"Retry"}');
                }
                return self::success();
            });
        $service = $this->makeService($transport, $filesystem, $mime, 3);
        try {
            $this->deliver($service, $response, $checkpoint);
            self::fail('The second occurrence must fail');
        } catch (RuntimeException $error) {
            self::assertSame($preparationFailure
                ? 'Generated Telegram file content is missing'
                : 'Telegram rejected generated file delivery', $error->getMessage());
        }
        self::assertSame('confirmed', $states[$firstKey]);
        self::assertSame('uncertain', $states[$secondKey]);
        self::assertSame('uncertain', $states['text']);
        self::assertNull(new ReflectionProperty($service, 'deliveryCheckpoint')->getValue($service));

        $this->deliver($service, $response, $checkpoint);
        $this->deliver($service, $response, $checkpoint);
        self::assertSame([$first, $second, $second], $preparations);
        self::assertSame([$first, null], $requests[0]);
        self::assertSame([$second, "Caption\n"], $requests[array_key_last($requests)]);
        self::assertSame(['text' => 'confirmed', $firstKey => 'confirmed', $secondKey => 'confirmed'], $states);
    }

    #[DataProvider('fileTypes')]
    public function testLongCaptionRetryDoesNotRepeatUploadsOrConfirmedChunks(string $mime, string $method): void
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('read')->willReturn('file bytes');
        $events = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::exactly(2))->method('postWithFiles')->willReturnCallback(
            static function (string $url, array $data, array $files) use (&$events, $method): ApiResponse {
                self::assertStringEndsWith('/' . $method, $url);
                self::assertArrayNotHasKey('caption', $data);
                $events[] = reset($files)->filename();
                return self::success();
            },
        );
        $chunks = 0;
        $transport->method('post')->willReturnCallback(
            static function (string $url, string $body) use (&$events, &$chunks): ApiResponse {
                if (str_ends_with($url, '/sendChatAction')) {
                    return new ApiResponse(200, '{"ok":true,"result":true}');
                }
                $events[] = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['text'];
                if (++$chunks === 2) {
                    return new ApiResponse(200, '{"ok":false,"error_code":429,"description":"Retry"}');
                }
                return self::success();
            },
        );
        $service = $this->makeService($transport, $filesystem, $mime, 2);
        $firstChunk = str_repeat('a', 4000);
        $response = '<img src="@@GENERATED@@first@@"><img src="@@GENERATED@@second@@">'
            . $firstChunk . "\n" . str_repeat('b', 1000);
        $states = [];
        $checkpoint = $this->checkpoint($states);
        try {
            $this->deliver($service, $response, $checkpoint);
            self::fail('The second text chunk must fail');
        } catch (RuntimeException $error) {
            self::assertSame('Telegram rejected a message chunk', $error->getMessage());
        }
        $this->deliver($service, $response, $checkpoint);
        self::assertSame([
            '@@GENERATED@@first@@', '@@GENERATED@@second@@',
            $firstChunk, str_repeat('b', 1000) . "\n", str_repeat('b', 1000) . "\n",
        ], $events);
    }

    #[DataProvider('fileTypes')]
    public function testWithoutCheckpointEveryOccurrenceIsSentOnEveryCall(string $mime, string $method): void
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('read')->willReturn('file bytes');
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('post')->willReturn(new ApiResponse(200, '{"ok":true,"result":true}'));
        $transport->expects(self::exactly(4))->method('postWithFiles')
            ->with(self::stringEndsWith('/' . $method), self::anything(), self::anything())
            ->willReturn(self::success());
        $service = $this->makeService($transport, $filesystem, $mime, 4);
        $send = new ReflectionMethod($service, 'sendChatResponse');
        $response = '<img src="@@GENERATED@@first@@"><img src="@@GENERATED@@first@@">Caption';
        $send->invoke($service, 42, $response);
        $send->invoke($service, 42, $response);
    }

    public static function deniedFiles(): iterable
    {
        foreach ([false, true] as $checkpointed) {
            yield ['user-1', 'missing', $checkpointed];
            yield ['user-1', 'foreign', $checkpointed];
            yield ['', 'foreign', $checkpointed];
            yield ['0', 'foreign', $checkpointed];
            yield [null, 'foreign', $checkpointed];
        }
    }

    #[DataProvider('deniedFiles')]
    public function testUnauthorizedFileFailsBeforeFilesystemOrTelegram(
        ?string $appUserId,
        string $fileId,
        bool $checkpointed,
    ): void {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('fileExists');
        $filesystem->expects(self::never())->method('read');
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('post');
        $transport->expects(self::never())->method('postWithFiles');
        $marker = '@@GENERATED@@' . $fileId . '@@';
        $repository = $this->createMock(FileRepository::class);
        $repository->expects($appUserId === 'user-1' ? self::once() : self::never())
            ->method('findOneBy')
            ->with(['fileId' => $marker, 'user' => 'user-1'])
            ->willReturn(null);
        $service = $this->makeService($transport, $filesystem, 'image/png', 0, $repository, $appUserId);
        $states = [];
        try {
            if ($checkpointed) {
                $this->deliver($service, $marker, $this->checkpoint($states));
            } else {
                new ReflectionMethod($service, 'sendChatResponse')->invoke($service, 42, $marker);
            }
            self::fail('Unauthorized files must not be delivered');
        } catch (RuntimeException $error) {
            self::assertSame('Generated Telegram file is missing', $error->getMessage());
        }
        if ($checkpointed) {
            self::assertSame([
                'text' => 'uncertain',
                'file:' . hash('sha256', $marker) . ':0:' . $marker => 'uncertain',
            ], $states);
        }
    }

    private function checkpoint(array &$states): \Closure
    {
        return static function (string $key, callable $operation) use (&$states): void {
            if (($states[$key] ?? null) === 'confirmed') {
                return;
            }
            $states[$key] = 'uncertain';
            $operation();
            $states[$key] = 'confirmed';
        };
    }

    private function deliver(TelegramService $service, string $response, callable $checkpoint): void
    {
        new ReflectionMethod($service, 'deliverChatResponse')->invoke($service, 42, $response, $checkpoint);
    }

    private static function success(): ApiResponse
    {
        return new ApiResponse(200,
            '{"ok":true,"result":{"message_id":1,"date":1,"chat":{"id":42,"type":"private"}}}');
    }

    private function makeService(
        TransportInterface $transport,
        Filesystem $filesystem,
        string $mime,
        int $lookups,
        ?FileRepository $repository = null,
        ?string $appUserId = 'user-1',
    ): TelegramService {
        if ($repository === null) {
            $repository = $this->createMock(FileRepository::class);
            $repository->expects(self::exactly($lookups))->method('findOneBy')->willReturnCallback(
                static function (array $criteria) use ($mime): File {
                    self::assertSame(['fileId' => $criteria['fileId'], 'user' => 'user-1'], $criteria);
                    self::assertContains($criteria['fileId'], ['@@GENERATED@@first@@', '@@GENERATED@@second@@']);
                    $file = new File();
                    $file->setFilePath($criteria['fileId']);
                    $file->setFilename($criteria['fileId']);
                    $file->setMimeType($mime);
                    return $file;
                },
            );
        }
        $entityManager = $this->createStub(EntityManager::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $session = new TelegramSession($entityManager);
        new ReflectionProperty($session, 'loaded')->setValue($session, true);
        new ReflectionProperty($session, 'storage')->setValue($session, [Auth::USERID => $appUserId]);
        $service = new ReflectionClass(TelegramService::class)->newInstanceWithoutConstructor();
        foreach ([
            'telegramBotApi' => new TelegramBotApi('test', transport: $transport),
            'telegramMarkdown' => new TelegramMarkdown(),
            'logger' => $this->createStub(LoggerInterface::class),
            'filesystem' => $filesystem,
            'entityManager' => $entityManager,
            'telegramSession' => $session,
        ] as $property => $value) {
            new ReflectionProperty($service, $property)->setValue($service, $value);
        }
        return $service;
    }
}
