<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\SemanticMemoryRegistry;
use App\Services\SemanticMemoryService;
use App\Services\Settings;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Migrations\Version20260930000200;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SemanticMemoryBindingsTest extends TestCase
{
    public function testDisabledMaintenanceDoesNotResolveAnEmbeddingProvider(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE account (id VARCHAR(255) PRIMARY KEY)');
        $migration = new Version20260930000200($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $builder = new ContainerBuilder();
        $builder->addDefinitions(Settings::getAppRoot() . '/config/dependencies.php');
        $builder->addDefinitions([
            Connection::class => $connection,
            LoggerInterface::class => new NullLogger(),
            Settings::class => new Settings([]),
            EmbeddingsProviderInterface::class => \DI\factory(static fn () =>
                throw new \RuntimeException('Disabled maintenance must not resolve embeddings')),
        ]);
        $memory = $builder->build()->get(SemanticMemoryService::class);
        $memory->sweep(5);
        self::assertSame('', $memory->recall('synthetic-owner', 'thread', 'query'));
        self::assertFalse($memory->index('synthetic-owner', 'document'));
    }

    public function testBindingsReuseRagProviderAndNeedNoExternalCallWithoutEligibleSources(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE account (id VARCHAR(255) PRIMARY KEY)');
        $connection->insert('account', ['id' => 'synthetic-owner']);
        $migration = new Version20260930000200($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $embeddings = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddings->expects(self::never())->method('embedText');
        $embeddings->expects(self::never())->method('embedDocuments');
        $builder = new ContainerBuilder();
        $builder->addDefinitions(Settings::getAppRoot() . '/config/dependencies.php');
        $builder->addDefinitions([
            Connection::class => $connection,
            EmbeddingsProviderInterface::class => $embeddings,
            LoggerInterface::class => new NullLogger(),
            Settings::class => new Settings(['llm' => [
                'openai' => ['baseUri' => 'https://synthetic.invalid/v1', 'modelEmbed' => 'synthetic-embedding'],
                'semanticMemory' => ['enabled' => true, 'path' => '/tmp/kilo/unused-semantic-binding',
                    'dimensions' => 3, 'maxCharacters' => 4000, 'topK' => 4],
            ]]),
        ]);
        $container = $builder->build();
        self::assertSame($embeddings, $container->get(EmbeddingsProviderInterface::class));
        $registry = $container->get(SemanticMemoryRegistry::class);
        self::assertFalse($registry->preference('synthetic-owner')['enabled']);
        $registry->setEnabled('synthetic-owner', true);
        $memory = $container->get(SemanticMemoryService::class);
        self::assertSame('', $memory->recall('synthetic-owner', 'current-thread', 'synthetic query'));
    }
}
