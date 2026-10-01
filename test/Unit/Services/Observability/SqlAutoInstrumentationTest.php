<?php

declare(strict_types=1);

namespace App\Test\Unit\Services\Observability;

use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\TestCase;

final class SqlAutoInstrumentationTest extends TestCase
{
    public function testSlimDispatchWorkerSqlAncestryWithoutDoctrineDuplicates(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('Requires proc_open for fresh auto-instrumentation bootstrap');
        }

        // Do not inherit application credentials, exporters, or PHP bootstrap overrides.
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/sql-auto-instrumentation.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4),
            ['QUEUE_TEST_REDIS_PORT' => getenv('QUEUE_TEST_REDIS_PORT') ?: ''],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode === 77) {
            self::markTestSkipped(trim($stdout));
        }

        self::assertSame(0, $exitCode, $stderr . $stdout);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(202, $result['httpStatus']);
        self::assertSame(1, $result['processed']);
        self::assertSame([41, 42, 43], $result['values']);
        self::assertFalse($result['contextAfterHttp']);
        self::assertFalse($result['contextAfterWorker']);
        self::assertSame(0, $result['spansAfterPrepare']);
        self::assertFalse($result['jobExistsAfterWorker']);

        $spans = $result['spans'];
        $byId = array_column($spans, null, 'id');
        $sql = [];
        $http = [];
        $jobs = [];
        foreach ($spans as $span) {
            self::assertStringNotContainsString('doctrine', strtolower($span['scope']));
            if (isset($span['attributes']['db.query.text'])) {
                self::assertSame('claire.sql', $span['scope']);
            }

            if ($span['scope'] === 'claire.sql') {
                $sql[] = $span;
            }

            if ($span['scope'] === 'io.opentelemetry.contrib.php.slim'
                && $span['kind'] === SpanKind::KIND_SERVER) {
                $http[] = $span;
            }

            if ($span['name'] === 'queue.process') {
                $jobs[] = $span;
            }
        }

        self::assertCount(1, $http, 'A real Slim auto-instrumented HTTP server span must exist');
        self::assertCount(1, $jobs);
        self::assertCount(5, $sql, 'One exec, two reused executes, one query and one HTTP query');
        $http = $http[0];
        $job = $jobs[0];
        self::assertSame(SpanKind::KIND_CONSUMER, $job['kind']);
        self::assertSame($http['trace'], $job['trace']);
        self::assertSame($result['dispatchSpanId'], $job['parent']);
        self::assertSame(
            '00-' . $http['trace'] . '-' . $result['dispatchSpanId'] . '-01',
            $result['storedTraceparent'],
        );

        // Slim also instruments route invocation; walk through that span to the HTTP server span.
        $ancestor = $job;
        for ($depth = 0; $ancestor['id'] !== $http['id'] && $depth < count($spans); $depth++) {
            self::assertArrayHasKey($ancestor['parent'], $byId, 'HTTP ancestry must remain in the same trace');
            $ancestor = $byId[$ancestor['parent']];
        }

        self::assertSame($http['id'], $ancestor['id']);

        $jobSql = 0;
        foreach ($sql as $span) {
            self::assertSame(SpanKind::KIND_CLIENT, $span['kind']);
            self::assertSame($http['trace'], $span['trace']);
            self::assertGreaterThanOrEqual(0, $span['duration']);
            if ($span['attributes']['db.query.text'] === 'SELECT 40') {
                self::assertSame($result['dispatchSpanId'], $span['parent']);
                continue;
            }

            $jobSql++;
            self::assertSame($job['id'], $span['parent']);
            self::assertSame('job', $span['attributes']['claire.sql.context']);
        }

        self::assertSame(4, $jobSql);
        self::assertSame(2, count(array_filter(
            $sql,
            static fn (array $span): bool => $span['attributes']['db.query.text'] === 'SELECT ?',
        )));
    }
}
