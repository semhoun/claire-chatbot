<?php

declare(strict_types=1);

use App\Exception;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Audio\MistralAudioService;
use App\Services\ComfyUIService;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\PdfGeneratorService;
use App\Services\Queue\QueueBackendInterface;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Queue\RedisQueueBackend;
use App\Services\RagService;
use App\Services\RagServiceInterface;
use App\Services\RedisClient;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use League\Flysystem\Filesystem;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Phptg\BotApi\TelegramBotApi;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

return [
    // Doctrine Dbal connection
    Connection::class => static function (Settings $settings, Doctrine\ORM\Configuration $configuration): Doctrine\DBAL\Connection {
        $connectionParams = [
            'driver' => 'pdo_' . $settings->get('database.driver'),
        ];

        if ($settings->get('database.driver') !== 'sqlite') {
            $connectionParams['host'] = $settings->get('database.host');
            $connectionParams['port'] = $settings->get('database.port');
            $connectionParams['dbname'] = $settings->get('database.dbname');
            $connectionParams['user'] = $settings->get('database.user');
            $connectionParams['password'] = $settings->get('database.password');
        }

        if ($settings->get('database.driver') === 'sqlite') {
            $connectionParams['path'] = $settings->get('database.path');
        }

        return DriverManager::getConnection($connectionParams, $configuration);
    },
    // Doctrine Config used by entity manager and Tracy
    Configuration::class => static function (Settings $settings): Doctrine\ORM\Configuration {
        $isDevMode = $settings->get('debug');
        $entityPaths = $settings->get('database.doctrine.entity_path');
        $cacheDir = $settings->get('cache_dir');

        $queryCache = $isDevMode ? new ArrayAdapter() : new PhpFilesAdapter('queries', 0, $cacheDir);
        $metadataCache = $isDevMode ? new ArrayAdapter() : new PhpFilesAdapter('metadata', 0, $cacheDir);

        // Configuration manuelle avec cache explicite
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            $entityPaths,
            $isDevMode,
            $cacheDir . '/proxy',
            $metadataCache
        );

        $configuration->setQueryCache($queryCache);
        $configuration->setProxyDir($cacheDir . '/proxy');
        $configuration->setProxyNamespace('App\\Proxies');

        $configuration->setAutoGenerateProxyClasses($isDevMode);
        $configuration->enableNativeLazyObjects(true);

        return $configuration;
    },
    // Doctrine EntityManager.
    EntityManager::class => static fn (Configuration $configuration, Connection $connection): EntityManager => new EntityManager($connection, $configuration),
    EntityManagerInterface::class => DI\get(EntityManager::class),
    // Settings.
    Settings::class => DI\factory([Settings::class, 'load']),
    Logger::class => static function (Settings $settings): Logger {
        $logger = new \Monolog\Logger($settings->get('logger.name'));
        $handlerOLTP = new \OpenTelemetry\Contrib\Logs\Monolog\Handler(
            \OpenTelemetry\API\Globals::loggerProvider(),
            $settings->get('logger.level'),
        );
        $logger->pushHandler($handlerOLTP);

        return $logger;
    },
    Twig::class => static function (Settings $settings): Twig {
        $twig = Twig::create(
            $settings->get('twig.template_path'),
            $settings->get('twig.config')
        );
        $twig->getEnvironment()->addGlobal('settings', $settings);

        return $twig;
    },
    Filesystem::class => static function (Settings $settings): FileSystem {
        if ($settings->get('files.fileSystem.type') === 'local') {
            $adapter = new League\Flysystem\Local\LocalFilesystemAdapter(
                $settings->get('files.fileSystem.path'),
            );
            return new League\Flysystem\Filesystem($adapter);
        }

        throw new Exception('Unknown filesystem type ' . $settings->get('files.fileSystem.type'));
    },
    TelegramBotApi::class => static fn (Settings $settings): TelegramBotApi => new TelegramBotApi($settings->get('telegram.bot_token')),
    ComfyUIWorkflowRegistry::class => static fn (Settings $settings): ComfyUIWorkflowRegistry => new ComfyUIWorkflowRegistry($settings),
    ComfyUIService::class => static fn (Settings $settings, Filesystem $filesystem, ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry, EntityManagerInterface $entityManager, \Psr\Log\LoggerInterface $logger): ComfyUIService => new ComfyUIService($settings, $filesystem, $comfyUIWorkflowRegistry, $entityManager, $logger),
    MistralAudioService::class => static fn (Settings $settings): MistralAudioService => new MistralAudioService($settings),
    AudioServiceInterface::class => DI\get(MistralAudioService::class),
    PdfGeneratorService::class => static fn (Settings $settings, Filesystem $filesystem, EntityManagerInterface $entityManager, \App\Services\Markdown $markdown): PdfGeneratorService => new PdfGeneratorService($settings, $filesystem, $entityManager, $markdown),
    RedisClient::class => static function (Settings $settings): RedisClient {
        $client = new RedisClient();
        $client->connect(
            (string) $settings->get('redis.host'),
            (int) $settings->get('redis.port'),
            (float) $settings->get('redis.timeout'),
        );

        $client->setReadTimeout((float) $settings->get('redis.readTimeout'));

        $password = $settings->get('redis.password');
        if (is_string($password) && $password !== '') {
            $client->auth($password);
        }

        $client->select((int) $settings->get('redis.database'));

        return $client;
    },
    QueueBackendInterface::class => DI\get(RedisQueueBackend::class),
    QueueDispatcherInterface::class => DI\get(QueueBackendInterface::class),
    RagServiceInterface::class => DI\get(RagService::class),
    EmbeddingsProviderInterface::class => static fn (Settings $settings): EmbeddingsProviderInterface => new OpenAILikeEmbeddings(
        baseUri: $settings->get('llm.openai.baseUri'),
        key: $settings->get('llm.openai.key'),
        model: $settings->get('llm.openai.modelEmbed'),
    ),
    VectorStoreInterface::class => static fn (Settings $settings): VectorStoreInterface => new FileVectorStore(
        directory: $settings->get('llm.rag.path'),
        name: 'neuron-rag',
    ),
];
