<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SseSettingsTest extends TestCase
{
    public function testDurationIsIndependentOfTheOpeningToken(): void
    {
        $previous = getenv('SSE_DURATION');
        putenv('SSE_DURATION');
        try {
            $settings = require Settings::getAppRoot() . '/config/settings/sse.php';
            self::assertSame(1800, $settings['duration']);
            self::assertSame(86400, $settings['max_duration']);
            self::assertArrayNotHasKey('stream_token_ttl', $settings);
            self::assertArrayNotHasKey('queue_ttl', $settings);
            self::assertArrayNotHasKey('pop_timeout', $settings);
        } finally {
            putenv($previous === false ? 'SSE_DURATION' : 'SSE_DURATION=' . $previous);
        }
    }

    #[DataProvider('invalidIntegers')]
    public function testMalformedValuesAreNotSilentlyTruncated(string $value): void
    {
        $previous = getenv('SSE_DURATION');
        putenv('SSE_DURATION=' . $value);
        try {
            $this->expectException(\InvalidArgumentException::class);
            require Settings::getAppRoot() . '/config/settings/sse.php';
        } finally {
            putenv($previous === false ? 'SSE_DURATION' : 'SSE_DURATION=' . $previous);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIntegers(): iterable
    {
        yield 'unit suffix' => ['1800s'];
        yield 'fraction' => ['1.5'];
        yield 'boolean' => ['true'];
        yield 'empty' => [''];
        yield 'overflow' => ['999999999999999999999999999'];
    }
}
