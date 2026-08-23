<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Enums\TelegramAction;
use App\Services\TelegramService;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class TelegramServiceTest extends TestCase
{
    public function testEveryChatActionIsSentToTelegram(): void
    {
        $requests = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::exactly(count(TelegramAction::cases())))
            ->method('post')
            ->willReturnCallback(static function (
                string $url,
                string $body,
            ) use (&$requests): ApiResponse {
                self::assertStringEndsWith('/sendChatAction', $url);
                $requests[] = json_decode(
                    $body,
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                return new ApiResponse(200, '{"ok":true,"result":true}');
            });

        $telegramService = $this->makeService($transport);
        $reflectionMethod = new ReflectionMethod($telegramService, 'sendChatAction');
        foreach (TelegramAction::cases() as $telegramAction) {
            $reflectionMethod->invoke($telegramService, 42, $telegramAction);
        }

        self::assertSame([
            ['chat_id' => 42, 'action' => 'typing'],
            ['chat_id' => 42, 'action' => 'record_video'],
            ['chat_id' => 42, 'action' => 'upload_photo'],
            ['chat_id' => 42, 'action' => 'upload_document'],
            ['chat_id' => 42, 'action' => 'record_voice'],
        ], $requests);
    }

    public function testChatActionIsThrottledAndRefreshedAfterFourSeconds(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::exactly(2))
            ->method('post')
            ->with(
                self::stringEndsWith('/sendChatAction'),
                self::callback(static function (string $body): bool {
                    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

                    return $data === ['chat_id' => 42, 'action' => 'typing'];
                }),
                self::anything(),
            )
            ->willReturn(new ApiResponse(200, '{"ok":true,"result":true}'));

        $telegramService = $this->makeService($transport);
        $reflectionMethod = new ReflectionMethod($telegramService, 'sendChatAction');

        $reflectionMethod->invoke($telegramService, 42, TelegramAction::TEXT);
        $reflectionMethod->invoke($telegramService, 42, TelegramAction::TEXT);

        $reflectionProperty = new ReflectionProperty($telegramService, 'lastChatActionAt');
        $reflectionProperty->setValue($telegramService, hrtime(true) / 1_000_000_000 - 4.0);

        $reflectionMethod->invoke($telegramService, 42, TelegramAction::TEXT);
    }

    private function makeService(
        TransportInterface $transport,
    ): TelegramService {
        $reflectionClass = new ReflectionClass(TelegramService::class);
        $telegramService = $reflectionClass->newInstanceWithoutConstructor();

        $telegramBotApi = new ReflectionProperty($telegramService, 'telegramBotApi');
        $telegramBotApi->setValue(
            $telegramService,
            new TelegramBotApi('token', transport: $transport),
        );

        $logger = new ReflectionProperty($telegramService, 'logger');
        $logger->setValue($telegramService, $this->createStub(LoggerInterface::class));

        return $telegramService;
    }
}
