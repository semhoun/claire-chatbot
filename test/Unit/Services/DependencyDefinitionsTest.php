<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Job\Web\NewMessageJob;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\TelegramService;
use DI\Definition\Reference;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DependencyDefinitionsTest extends TestCase
{
    public function testQueueConsumerDefinitionsMatchTheirConstructors(): void
    {
        $definitions = require dirname(__DIR__, 3) . '/config/dependencies.php';

        foreach ([NewMessageJob::class, TelegramService::class] as $class) {
            $definition = $definitions[$class]->getDefinition($class);
            $injection = $definition->getConstructorInjection();
            self::assertNotNull($injection);
            $parameters = $injection->getParameters();
            $constructor = new ReflectionMethod($class, '__construct');
            $names = array_column($constructor->getParameters(), 'name');

            $position = array_search('queueDispatcher', $names, true);
            self::assertNotFalse($position);
            self::assertArrayHasKey($position, $parameters);
            self::assertInstanceOf(Reference::class, $parameters[$position]);
            self::assertSame(QueueDispatcherInterface::class, $parameters[$position]->getTargetEntryName());
        }
    }
}
