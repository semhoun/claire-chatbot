<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase
{
    private const string ENV_KEY = 'CLAIRE_TEST_SECRET';

    private const string COMPROMISED_JWT_SECRET =
        'changeme00changeme00changeme00changeme00changeme00changeme00';

    protected function tearDown(): void
    {
        putenv(self::ENV_KEY);
    }

    public function testGetSecretReturnsValidSecret(): void
    {
        $secret = str_repeat('secure-value-', 3);
        putenv(sprintf('%s=%s', self::ENV_KEY, $secret));

        self::assertSame($secret, Env::getSecret(self::ENV_KEY));
    }

    public function testGetSecretRejectsMissingValue(): void
    {
        putenv(self::ENV_KEY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing or empty secret environment variable');

        Env::getSecret(self::ENV_KEY);
    }

    public function testGetSecretRejectsShortValue(): void
    {
        putenv(self::ENV_KEY . '=too-short');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must contain at least 32 bytes');

        Env::getSecret(self::ENV_KEY);
    }

    public function testGetSecretRejectsForbiddenValue(): void
    {
        $secret = str_repeat('compromised-', 3);
        putenv(sprintf('%s=%s', self::ENV_KEY, $secret));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses a forbidden value');

        Env::getSecret(self::ENV_KEY, disallowedValues: [$secret]);
    }

    public function testSessionSettingsRejectCompromisedJwtSecret(): void
    {
        putenv('SESSION_JWT_SECRET=' . self::COMPROMISED_JWT_SECRET);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SESSION_JWT_SECRET uses a forbidden value');

            require dirname(__DIR__, 3) . '/config/settings/session.php';
        } finally {
            putenv('SESSION_JWT_SECRET');
        }
    }
}
