<?php

declare(strict_types=1);

use App\Services\Observability\SqlInstrumentation;
use App\Services\Observability\SqlMiddleware;
use App\Services\Queue\QueueDoer;
use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\QueueWorker;
use App\Services\Queue\QueueWorkerOptions;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Settings;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

// These checks precede Composer so a missing extension is a clear skip, not an autoload warning.
foreach (['opentelemetry', 'redis', 'pdo_sqlite', 'pcntl', 'posix'] as $extension) {
    if (! extension_loaded($extension)) {
        fwrite(STDOUT, 'Requires ext-' . $extension . ' in the standalone PHP process');
        exit(77);
    }
}

$port = filter_var(getenv('QUEUE_TEST_REDIS_PORT'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
if ($port === false) {
    fwrite(STDOUT, 'Requires isolated Redis explicitly selected with QUEUE_TEST_REDIS_PORT');
    exit(77);
}

// Enable installed auto-instrumentations without excluding Doctrine to detect duplicate SQL spans.
foreach ([
    'OTEL_PHP_AUTOLOAD_ENABLED' => 'true',
    'OTEL_PHP_DISABLED_INSTRUMENTATIONS' => '',
    'OTEL_SDK_DISABLED' => 'false',
    'OTEL_TRACES_EXPORTER' => 'none',
    'OTEL_METRICS_EXPORTER' => 'none',
    'OTEL_LOGS_EXPORTER' => 'none',
    'OTEL_PROPAGATORS' => 'tracecontext',
] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require dirname(__DIR__, 5) . '/vendor/autoload.php';

final class SqlAutoInstrumentationJob implements QueueDoer
{
    /** @var list<int> */
    public static array $values = [];

    public function __construct(private readonly Connection $connection, private readonly Statement $statement)
    {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    public function handle(array $payload): void
    {
        $this->connection->executeStatement('CREATE TABLE integration_test (id INTEGER)');
        foreach ([41, 42] as $value) {
            $this->statement->bindValue(1, $value);
            self::$values[] = (int) $this->statement->executeQuery()->fetchOne();
        }

        self::$values[] = (int) $this->connection->executeQuery('SELECT 43')->fetchOne();
    }
}

$redis = new Redis();
try {
    if (! @$redis->connect('127.0.0.1', $port, 1.0) || ! $redis->ping()) {
        throw new RuntimeException('Redis unavailable');
    }
} catch (Throwable) {
    fwrite(STDOUT, 'Isolated Redis is unavailable at QUEUE_TEST_REDIS_PORT');
    exit(77);
}

$prefix = 'sql-auto-test:' . bin2hex(random_bytes(12)) . ':';
try {
    $exporter = new InMemoryExporter();
    $provider = TracerProvider::builder()
        ->addSpanProcessor(new SimpleSpanProcessor($exporter))
        ->setSampler(new AlwaysOnSampler())
        ->build();
    // A global initializer survives worker extraction onto Context::getRoot().
    Globals::registerInitializer(static fn (Configurator $configurator): Configurator => $configurator
        ->withTracerProvider($provider)
        ->withPropagator(TraceContextPropagator::getInstance()));

    $settings = new Settings([
        'redis' => [
            'host' => '127.0.0.1', 'port' => $port, 'timeout' => 1.0,
            'database' => 0, 'password' => null, 'prefix' => $prefix,
        ],
        'queue' => ['leaseSeconds' => 30, 'maxAttempts' => 1, 'retryDelaySeconds' => 1],
    ]);
    $configuration = new Configuration();
    $configuration->setMiddlewares([new SqlMiddleware(new SqlInstrumentation(enabled: true))]);
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
    $statement = $connection->prepare('SELECT ?');
    $result = ['spansAfterPrepare' => count($exporter->getSpans())];
    $backend = new RedisQueueBackend(new QueueRedisConnection($settings), $settings, $connection);
    $job = new SqlAutoInstrumentationJob($connection, $statement);
    $container = new readonly class ($job) implements ContainerInterface {
        public function __construct(private SqlAutoInstrumentationJob $sqlAutoInstrumentationJob)
        {
        }

        public function get(string $id): mixed
        {
            if (! $this->has($id)) {
                throw new RuntimeException('Unexpected fixture dependency: ' . $id);
            }

            return $this->sqlAutoInstrumentationJob;
        }

        public function has(string $id): bool
        {
            return $id === SqlAutoInstrumentationJob::class;
        }
    };

    $app = AppFactory::create();
    $app->post('/dispatch', static function (
        ServerRequestInterface $serverRequest,
        ResponseInterface $response,
    ) use ($backend, $connection, &$result): ResponseInterface {
        $result['dispatchSpanId'] = Span::getCurrent()->getContext()->getSpanId();
        $connection->executeQuery('SELECT 40')->fetchOne();
        $result['jobId'] = $backend->dispatch(SqlAutoInstrumentationJob::class, [], 'integration');
        return $response->withStatus(202);
    });
    $response = $app->handle(new ServerRequestFactory()->createServerRequest('POST', 'http://localhost/dispatch'));
    $result['httpStatus'] = $response->getStatusCode();
    $result['contextAfterHttp'] = Span::getCurrent()->getContext()->isValid();
    $jobKey = $prefix . 'queue:job:' . $result['jobId'];
    $result['storedTraceparent'] = $redis->hGet($jobKey, 'traceparent');
    $result['processed'] = new QueueWorker($backend, $container, new NullLogger())
        ->run(new QueueWorkerOptions('integration', 0, 1, 5), 'integration-worker');
    $result['contextAfterWorker'] = Span::getCurrent()->getContext()->isValid();
    $result['jobExistsAfterWorker'] = (bool) $redis->exists($jobKey);
    $result['values'] = SqlAutoInstrumentationJob::$values;
    $provider->forceFlush();
    $result['spans'] = [];
    foreach ($exporter->getSpans() as $span) {
        $result['spans'][] = [
            'id' => $span->getContext()->getSpanId(),
            'trace' => $span->getContext()->getTraceId(),
            'parent' => $span->getParentContext()->getSpanId(),
            'scope' => $span->getInstrumentationScope()->getName(),
            'name' => $span->getName(),
            'kind' => $span->getKind(),
            'duration' => $span->getEndEpochNanos() - $span->getStartEpochNanos(),
            'attributes' => $span->getAttributes()->toArray(),
        ];
    }

    $provider->shutdown();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} finally {
    // Only this fixture's random namespace is removed; never flush the Redis database.
    $keys = $redis->keys($prefix . '*');
    if ($keys !== []) {
        $redis->del($keys);
    }

    $redis->close();
}
