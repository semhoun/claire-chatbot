<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\QueueScripts;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Settings;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueDispatchTest extends TestCase
{
    public function testDispatchPassesOnlyValidW3cFieldsToAtomicScript(): void
    {
        $parent = '00-11111111111111111111111111111111-2222222222222222-01';
        foreach ([
            ['traceparent' => $parent, 'tracestate' => 'vendor=value'],
            ['traceparent' => str_replace('-01', '-00', $parent)],
            ['traceparent' => $parent, 'tracestate' => 'invalid state'],
            ['traceparent' => 'invalid', 'tracestate' => 'vendor=value'],
            [],
        ] as $carrier) {
            $context = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot());
            $context = Baggage::getBuilder()->set('private', 'sentinel')->build()->storeInContext($context);
            $scope = $context->activate();
            try {
                $expectedParent = ($carrier['traceparent'] ?? '') === 'invalid'
                    ? '' : ($carrier['traceparent'] ?? '');
                $expectedState = $expectedParent !== '' && ($carrier['tracestate'] ?? '') === 'vendor=value'
                    ? 'vendor=value' : '';
                $redis = $this->createMock(QueueRedisConnection::class);
                $redis->expects(self::once())->method('evaluate')->willReturnCallback(
                    static function (string $script, array $arguments, int $keyCount) use (
                        $expectedParent, $expectedState,
                    ): string {
                        self::assertSame(QueueScripts::DISPATCH, $script);
                        self::assertSame(2, $keyCount);
                        self::assertCount(12, $arguments);
                        self::assertSame('{"business":"unchanged"}', $arguments[5]);
                        self::assertSame($expectedParent, $arguments[10]);
                        self::assertSame($expectedState, $arguments[11]);
                        self::assertStringNotContainsString('sentinel', implode('', $arguments));
                        return $arguments[2];
                    },
                );
                $backend = new RedisQueueBackend($redis, new Settings(['redis' => ['prefix' => 'test:']]),
                    $this->createStub(\Doctrine\DBAL\Connection::class));
                $backend->dispatch('ExampleJob', ['business' => 'unchanged'], 'test');
            } finally {
                $scope->detach();
            }
        }
    }

    public function testDependencyAliasesResolveToRedisBackend(): void
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(Settings::getAppRoot() . '/config/dependencies.php');
        $builder->addDefinitions([
            Settings::class => new Settings([]),
            \Doctrine\DBAL\Connection::class => $this->createStub(\Doctrine\DBAL\Connection::class),
        ]);
        $container = $builder->build();
        $backend = $container->get(\App\Services\Queue\QueueBackendInterface::class);

        self::assertInstanceOf(RedisQueueBackend::class, $backend);
        self::assertInstanceOf(\App\Services\Queue\LeasedQueueBackendInterface::class, $backend);
        self::assertSame($container->get(RedisQueueBackend::class), $backend);
        self::assertSame($backend, $container->get(\App\Services\Queue\QueueDispatcherInterface::class));
    }

    public function testMalformedWebPayloadNeverTouchesRedisOrSql(): void
    {
        $redis = $this->createMock(QueueRedisConnection::class);
        $redis->expects(self::never())->method('evaluate');
        $sql = $this->createMock(\Doctrine\DBAL\Connection::class);
        $sql->expects(self::never())->method('getNativeConnection');
        $backend = new RedisQueueBackend($redis, new Settings(['redis' => ['prefix' => 'test:']]), $sql);
        $valid = ['threadId' => 'thread', 'sessionId' => 'tab', 'messageId' => 'message-test',
            'submissionId' => 'submission-test', 'message' => 'hello',
            'session' => [\App\Services\Auth::USERID => 'user']];
        foreach ([['session' => 'invalid'], ['messageId' => ''], ['message' => []],
            ['submissionId' => null], ['submissionId' => []], ['submissionId' => 'invalid/id'],
            ['threadId' => ''], ['attachments' => ['fileIds' => 'invalid']]] as $invalid) {
            try {
                $backend->dispatch(\App\Job\Web\NewMessageJob::class, array_replace($valid, $invalid), 'test');
                self::fail('Malformed payload accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEnqueueFailureIsPropagated(): void
    {
        $connection = $this->createMock(QueueRedisConnection::class);
        $connection->expects(self::once())->method('evaluate')
            ->willThrowException(new RuntimeException('Redis unavailable'));
        $backend = new RedisQueueBackend($connection, new Settings(['redis' => ['prefix' => 'test:']]),
            $this->createStub(\Doctrine\DBAL\Connection::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable');
        $backend->dispatch('ExampleJob', [], 'test');
    }

    public function testInvalidPayloadIsRejectedBeforeRedisWrites(): void
    {
        $connection = $this->createMock(QueueRedisConnection::class);
        $connection->expects(self::never())->method('evaluate');
        $backend = new RedisQueueBackend($connection, new Settings(['redis' => ['prefix' => 'test:']]),
            $this->createStub(\Doctrine\DBAL\Connection::class));
        $this->expectException(RuntimeException::class);
        $backend->dispatch('ExampleJob', ['invalid' => "\xB1"], 'test');
    }
}
