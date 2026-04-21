<?php

declare(strict_types=1);

namespace Test\Unit\Services;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Services\Settings;
use App\Services\TelegramValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TelegramWebAppValidatorTest extends TestCase
{
    private TelegramValidator $validator;
    private LoggerInterface&MockObject $logger;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    private function createValidatorWithSettings(array $settings): TelegramValidator
    {
        return new TelegramValidator(
            $this->logger,
            $this->entityManager,
            new Settings($settings),
        );
    }

    public function testAppGetTelegramUserIdWithEmptyDataReturnsNull(): void
    {
        $validator = $this->createValidatorWithSettings([]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Empty initData or botToken');

        self::assertNull($validator->appGetTelegramUserId(''));
    }

    public function testAppGetTelegramUserIdWithEmptyBotTokenReturnsNull(): void
    {
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => '']]);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Empty botToken');

        $initData = 'user={"id":123}&hash=abc';
        self::assertNull($validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithoutHashReturnsNull(): void
    {
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => 'test_token']]);

        $initData = 'user={"id":123}';
        self::assertNull($validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithInvalidHashReturnsNull(): void
    {
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => 'test_token']]);

        $initData = 'user={"id":123}&auth_date=1234567890&hash=invalid_hash';
        self::assertNull($validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithValidHashButNoUserInDbReturnsNull(): void
    {
        $botToken = 'test_token_12345';
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => $botToken]]);

        // Build valid init data
        $data = [
            'user' => '{"id":123,"first_name":"Test"}',
            'auth_date' => '1234567890',
            'query_id' => 'test_query',
        ];
        ksort($data);

        $dataCheckString = '';
        foreach ($data as $key => $value) {
            $dataCheckString .= $key . '=' . $value . "\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $data['hash'] = $hash;
        $initData = urldecode(http_build_query($data));

        // User not found in database
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByTelegramId')
            ->with('123')
            ->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        self::assertNull($validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithValidHashAndUserInDbReturnsUserId(): void
    {
        $botToken = 'test_token_12345';
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => $botToken]]);

        // Build valid init data
        $data = [
            'user' => '{"id":123,"first_name":"Test"}',
            'auth_date' => '1234567890',
            'query_id' => 'test_query',
        ];
        ksort($data);

        $dataCheckString = '';
        foreach ($data as $key => $value) {
            $dataCheckString .= $key . '=' . $value . "\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $data['hash'] = $hash;
        $initData = urldecode(http_build_query($data));

        // User found in database
        $user = $this->createMock(User::class);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByTelegramId')
            ->with('123')
            ->willReturn($user);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        self::assertSame('123', $validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithoutUserFieldReturnsNull(): void
    {
        $botToken = 'test_token_12345';
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => $botToken]]);

        // Build valid init data without user field
        $data = [
            'auth_date' => '1234567890',
            'query_id' => 'test_query',
        ];
        ksort($data);

        $dataCheckString = '';
        foreach ($data as $key => $value) {
            $dataCheckString .= $key . '=' . $value . "\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $data['hash'] = $hash;
        $initData = urldecode(http_build_query($data));

        self::assertNull($validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithInvalidJsonReturnsNull(): void
    {
        $botToken = 'test_token_12345';
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => $botToken]]);

        // Build valid init data with invalid user JSON
        $data = [
            'user' => 'invalid_json',
            'auth_date' => '1234567890',
        ];
        ksort($data);

        $dataCheckString = '';
        foreach ($data as $key => $value) {
            $dataCheckString .= $key . '=' . $value . "\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $data['hash'] = $hash;
        $initData = urldecode(http_build_query($data));

        self::assertNull($validator->appGetTelegramUserId($initData));
    }

    public function testAppGetTelegramUserIdWithStringId(): void
    {
        $botToken = 'test_token_12345';
        $validator = $this->createValidatorWithSettings(['telegram' => ['bot_token' => $botToken]]);

        // Build valid init data with string user ID
        $data = [
            'user' => '{"id":"987654321","first_name":"Jane"}',
            'auth_date' => '1234567890',
        ];
        ksort($data);

        $dataCheckString = '';
        foreach ($data as $key => $value) {
            $dataCheckString .= $key . '=' . $value . "\n";
        }
        $dataCheckString = rtrim($dataCheckString, "\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $data['hash'] = $hash;
        $initData = urldecode(http_build_query($data));

        // User found in database
        $user = $this->createMock(User::class);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByTelegramId')
            ->with('987654321')
            ->willReturn($user);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        self::assertSame('987654321', $validator->appGetTelegramUserId($initData));
    }
}
