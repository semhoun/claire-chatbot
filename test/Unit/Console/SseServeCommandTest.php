<?php

declare(strict_types=1);

namespace App\Test\Unit\Console;

use App\Console\SseServeCommand;
use App\Services\Settings;
use App\Sse\Daemon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SseServeCommandTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function helpArguments(): iterable
    {
        yield 'command help' => [['sse:serve', '--help']];
        yield 'leading help' => [['--help', 'sse:serve']];
        yield 'help command' => [['help', 'sse:serve']];
        yield 'namespace abbreviation' => [['sse', '--help']];
        yield 'command abbreviation' => [['sse:ser', '--help']];
        yield 'namespace segment abbreviation' => [['s:serve', '--help']];
        yield 'help with abbreviation' => [['help', 'sse:ser']];
        yield 'both segments abbreviated' => [['ss:s', '--help']];
        yield 'case insensitive abbreviation' => [['S:SER', '--help']];
        yield 'abbreviated help command' => [['he', 'sse:ser']];
        yield 'omitted namespace' => [[':serve', '--help']];
        yield 'omitted namespace with abbreviation' => [[':ser', '--help']];
        yield 'omitted namespace with longer abbreviation' => [[':serv', '--help']];
        yield 'omitted namespace with case insensitive abbreviation' => [[':SER', '--help']];
        yield 'help with omitted namespace' => [['help', ':ser']];
        yield 'global flags before command' => [['--no-interaction', '--no-ansi', '-vvv', 'sse:serve', '--help']];
        yield 'global flags after command' => [['sse:serve', '-n', '--no-ansi', '-v', '-h']];
        yield 'help with global flags' => [['-n', '--no-ansi', 'help', 'sse:serve']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('helpArguments')]
    public function testHelpDoesNotStartTheDaemon(array $arguments): void
    {
        $result = $this->runConsole($arguments);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('sse:serve', $result['output']);
        self::assertStringContainsString('Usage:', $result['output']);
        self::assertStringNotContainsString('SSE daemon failed', $result['output']);
        $this->assertIsolatedBootstrap($result['diagnostics']);
        self::assertNotContains('React\\Socket\\SocketServer', $result['diagnostics']['classes']);
    }

    public function testInvalidOptionFailsWithoutStartingTheDaemon(): void
    {
        $result = $this->runConsole(['--no-interaction', 'sse:serve', '--not-a-real-sse-option']);

        self::assertNotSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('not-a-real-sse-option', $result['output']);
        self::assertStringContainsString('does not exist', $result['output']);
        self::assertStringNotContainsString('SSE daemon failed', $result['output']);
        $this->assertIsolatedBootstrap($result['diagnostics']);
        self::assertNotContains('React\\Socket\\SocketServer', $result['diagnostics']['classes']);
    }

    public function testNonIsolatedCommandCannotRunTheDaemon(): void
    {
        $tester = new CommandTester(new SseServeCommand(new Daemon(new Settings([]))));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('run ./console sse:serve', $tester->getDisplay());
        self::assertStringNotContainsString('SSE daemon failed', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function startupAbbreviations(): iterable
    {
        yield 'alias' => ['sse'];
        yield 'command abbreviation' => ['sse:ser'];
        yield 'namespace abbreviation' => ['s:serve'];
        yield 'both segments abbreviated' => ['s:ser'];
        yield 'omitted namespace' => [':serve'];
        yield 'omitted namespace with abbreviation' => [':ser'];
    }

    #[DataProvider('startupAbbreviations')]
    public function testAbbreviatedStartupUsesIsolatedBootstrap(string $command): void
    {
        $result = $this->runConsole(['-n', $command]);

        self::assertSame(1, $result['exitCode'], $result['output']);
        self::assertSame(
            'SSE daemon failed; check configuration and local dependencies.',
            trim($result['output']),
        );
        $this->assertIsolatedBootstrap($result['diagnostics']);
        self::assertSame(1, $result['diagnostics']['settingsLoads']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function brokenStartupEnvironments(): iterable
    {
        yield 'invalid database driver' => [[]];
        yield 'SQLite directory does not exist' => [['DATABASE_KIND' => 'sqlite']];
        yield 'invalid secret is not disclosed' => [['SSE_INTERNAL_SECRET' => "secret-canary-invalid\nheader"]];
        yield 'settings exception is sanitized' => [['SSE_DURATION' => 'invalid-duration-canary']];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('brokenStartupEnvironments')]
    public function testStartupFailureIsGenericAndBootstrapIsIsolated(array $environment): void
    {
        $result = $this->runConsole(['--no-interaction', '-vvv', 'sse:serve'], $environment);

        self::assertSame(1, $result['exitCode'], $result['output']);
        self::assertSame(
            'SSE daemon failed; check configuration and local dependencies.',
            trim($result['output']),
        );
        foreach (['database-password-canary', 'redis-password-canary', 'secret-canary-invalid',
            'session-secret-canary-for-sse-cli-tests-only',
            'invalid-duration-canary', 'Stack trace', 'SSE_INTERNAL_SECRET'] as $secret) {
            self::assertStringNotContainsString($secret, $result['output']);
        }
        $this->assertIsolatedBootstrap($result['diagnostics']);
        self::assertSame(1, $result['diagnostics']['settingsLoads']);
    }

    /** @param array{classes: list<string>, settingsLoads: int, otel: string|false} $diagnostics */
    private function assertIsolatedBootstrap(array $diagnostics): void
    {
        self::assertSame('false', $diagnostics['otel']);
        self::assertLessThanOrEqual(1, $diagnostics['settingsLoads']);
        foreach (['Doctrine\\ORM\\EntityManager', 'Doctrine\\DBAL\\DriverManager',
            'App\\Services\\RedisClient', 'Monolog\\Logger',
            'OpenTelemetry\\Contrib\\Logs\\Monolog\\Handler'] as $class) {
            self::assertNotContains($class, $diagnostics['classes'], $class . ' loaded by SSE bootstrap');
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{exitCode: int, output: string,
     *     diagnostics: array{classes: list<string>, settingsLoads: int, otel: string|false}}
     */
    private function runConsole(array $arguments, array $environment = []): array
    {
        $root = dirname(__DIR__, 3);
        $hook = tempnam(sys_get_temp_dir(), 'claire-sse-hook-');
        $diagnostics = tempnam(sys_get_temp_dir(), 'claire-sse-diagnostics-');
        self::assertNotFalse($hook);
        self::assertNotFalse($diagnostics);
        $dataPath = $diagnostics . '-missing/data';
        $process = null;
        $pipes = [];

        try {
            // Count a lookup made once per real Settings::load(), without replacing Settings.
            file_put_contents($hook, <<<'PHP'
<?php
namespace App\Services;

function getenv(string $name, bool $localOnly = false): string|false
{
    if ($name === 'APP_NAME') {
        ++$GLOBALS['sseTestSettingsLoads'];
    }
    return \getenv($name, $localOnly);
}

$GLOBALS['sseTestSettingsLoads'] = 0;
register_shutdown_function(static function (): void {
    file_put_contents(\getenv('SSE_TEST_DIAGNOSTICS'), json_encode([
        'classes' => get_declared_classes(),
        'settingsLoads' => $GLOBALS['sseTestSettingsLoads'],
        'otel' => \getenv('OTEL_PHP_AUTOLOAD_ENABLED'),
    ], JSON_THROW_ON_ERROR));
});
PHP);
            // Do not inherit deployment secrets, .env settings, or telemetry configuration.
            $process = proc_open([
                PHP_BINARY, '-d', 'auto_prepend_file=' . $hook,
                '-d', 'auto_append_file=', '-d', 'opcache.enable_cli=0',
                $root . '/console', ...$arguments,
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [
                'PATH' => (string) getenv('PATH'),
                'BASE_URL' => 'http://127.0.0.1',
                'OPENAPI_URL' => 'http://127.0.0.1:1',
                'OPENAPI_MODEL' => 'sse-test',
                'OPENID_WELLKNOWN_URL' => 'http://127.0.0.1:1/.well-known/openid-configuration',
                'OPENID_CLIENT_ID' => 'sse-test',
                'SESSION_JWT_SECRET' => 'session-secret-canary-for-sse-cli-tests-only',
                'DEBUG_MODE' => 'true',
                'DATABASE_KIND' => 'invalid-sse-test-driver',
                'DATABASE_PASSWORD' => 'database-password-canary',
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => '1',
                'REDIS_PASSWORD' => 'redis-password-canary',
                'DATA_PATH' => $dataPath,
                'SSE_TEST_DIAGNOSTICS' => $diagnostics,
                'OTEL_PHP_AUTOLOAD_ENABLED' => 'true',
                ...$environment,
            ]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $deadline = microtime(true) + 5;
            do {
                $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    self::fail('Console did not exit within five seconds: ' . implode(' ', $arguments) . "\n" . $output);
                }
                usleep(10000);
            } while (true);
            $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            self::assertDirectoryDoesNotExist($dataPath);
            self::assertNotSame('', file_get_contents($diagnostics), $output);

            return [
                'exitCode' => $status['exitcode'],
                'output' => $output,
                'diagnostics' => json_decode(file_get_contents($diagnostics), true, flags: JSON_THROW_ON_ERROR),
            ];
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
            unlink($hook);
            unlink($diagnostics);
        }
    }
}
