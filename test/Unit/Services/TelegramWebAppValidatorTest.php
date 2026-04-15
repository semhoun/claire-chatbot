<?php

declare(strict_types=1);

namespace Test\Unit\Services;

use App\Services\TelegramWebAppValidator;
use PHPUnit\Framework\TestCase;

final class TelegramWebAppValidatorTest extends TestCase
{
    private TelegramWebAppValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TelegramWebAppValidator();
    }

    public function testValidateInitDataWithEmptyDataReturnsFalse(): void
    {
        self::assertFalse($this->validator->validateInitData('', 'bot_token'));
    }

    public function testValidateInitDataWithEmptyTokenReturnsFalse(): void
    {
        self::assertFalse($this->validator->validateInitData('some=data', ''));
    }

    public function testValidateInitDataWithoutHashReturnsFalse(): void
    {
        $initData = 'user={"id":123}';
        self::assertFalse($this->validator->validateInitData($initData, 'bot_token'));
    }

    public function testValidateInitDataWithValidHashReturnsTrue(): void
    {
        $botToken = 'test_token_12345';

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
        $initData = http_build_query($data);
        // http_build_query encodes, we need to decode for our test
        $initData = urldecode($initData);

        self::assertTrue($this->validator->validateInitData($initData, $botToken));
    }

    public function testValidateInitDataWithInvalidHashReturnsFalse(): void
    {
        $initData = 'user={"id":123}&auth_date=1234567890&hash=invalid_hash';
        self::assertFalse($this->validator->validateInitData($initData, 'bot_token'));
    }

    public function testExtractUserIdWithValidUserData(): void
    {
        $userData = '{"id":123456,"first_name":"John"}';
        $initData = 'user=' . urlencode($userData) . '&auth_date=1234567890';

        self::assertSame('123456', $this->validator->extractUserId($initData));
    }

    public function testExtractUserIdWithStringId(): void
    {
        $userData = '{"id":"987654321","first_name":"Jane"}';
        $initData = 'user=' . urlencode($userData);

        self::assertSame('987654321', $this->validator->extractUserId($initData));
    }

    public function testExtractUserIdWithEmptyDataReturnsNull(): void
    {
        self::assertNull($this->validator->extractUserId(''));
    }

    public function testExtractUserIdWithoutUserFieldReturnsNull(): void
    {
        self::assertNull($this->validator->extractUserId('auth_date=1234567890'));
    }

    public function testExtractUserIdWithInvalidJsonReturnsNull(): void
    {
        $initData = 'user=invalid_json&auth_date=1234567890';
        self::assertNull($this->validator->extractUserId($initData));
    }

    public function testExtractUserDataWithValidData(): void
    {
        $userData = '{"id":123,"first_name":"John","last_name":"Doe"}';
        $initData = 'user=' . urlencode($userData);

        $result = $this->validator->extractUserData($initData);

        self::assertIsArray($result);
        self::assertSame(123, $result['id']);
        self::assertSame('John', $result['first_name']);
        self::assertSame('Doe', $result['last_name']);
    }

    public function testExtractUserDataWithEmptyDataReturnsNull(): void
    {
        self::assertNull($this->validator->extractUserData(''));
    }

    public function testExtractUserDataWithInvalidJsonReturnsNull(): void
    {
        $initData = 'user=invalid_json';
        self::assertNull($this->validator->extractUserData($initData));
    }

    public function testIsInitDataFreshWithRecentAuthDate(): void
    {
        $authDate = (string) time();
        $initData = 'user={"id":123}&auth_date=' . $authDate;

        self::assertTrue($this->validator->isInitDataFresh($initData));
    }

    public function testIsInitDataFreshWithOldAuthDate(): void
    {
        $oldAuthDate = (string) (time() - 100000); // More than 24 hours ago
        $initData = 'user={"id":123}&auth_date=' . $oldAuthDate;

        self::assertFalse($this->validator->isInitDataFresh($initData));
    }

    public function testIsInitDataFreshWithoutAuthDate(): void
    {
        $initData = 'user={"id":123}';
        self::assertFalse($this->validator->isInitDataFresh($initData));
    }

    public function testIsInitDataFreshWithCustomMaxAge(): void
    {
        $recentAuthDate = (string) (time() - 30); // 30 seconds ago
        $initData = 'user={"id":123}&auth_date=' . $recentAuthDate;

        self::assertTrue($this->validator->isInitDataFresh($initData, 60)); // 60 seconds max
        self::assertFalse($this->validator->isInitDataFresh($initData, 10)); // 10 seconds max
    }
}
