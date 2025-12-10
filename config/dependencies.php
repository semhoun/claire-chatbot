<?php

declare(strict_types=1);

use App\Exception;
use App\Brain\Claire;
use App\Brain\Summary;
use App\Services\OidcClient;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use League\Flysystem\Filesystem;
use Monolog\Logger;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Odan\Session\PhpSession;
use Odan\Session\SessionInterface;
use Odan\Session\SessionManagerInterface;
use OneToMany\Twig\FilesizeExtension;
use Slim\Views\Twig;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Twig\Extension\DebugExtension;
use Twig\Extension\ProfilerExtension;
use Twig\Extra\Markdown\DefaultMarkdown;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Profiler\Profile;

return [
    // Doctrine Dbal connection
    Connection::class => static function (Settings $settings, Doctrine\ORM\Configuration $configuration): Doctrine\DBAL\Connection {
        $connectionParams = [
            'driver' => 'pdo_' . $settings->get('database.driver'),
        ];
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

        return $configuration;
    },
    // Doctrine EntityManager.
    EntityManager::class => static fn (Configuration $configuration, Connection $connection): EntityManager => new EntityManager($connection, $configuration),
    EntityManagerInterface::class => DI\get(EntityManager::class),
    // Settings.
    Settings::class => DI\factory([Settings::class, 'load']),
    Logger::class => static function (Settings $settings): Logger {
        $logger = new Logger($settings->get('logger.name'));
        $handlerOLTP = new \OpenTelemetry\Contrib\Logs\Monolog\Handler(
            \OpenTelemetry\API\Globals::loggerProvider(),
            $settings->get('logger.level'),
        );
        $logger->pushHandler($handlerOLTP);

        return $logger;
    },
    Twig::class => static function (Settings $settings, Profile $profile): Twig {
        $twig = Twig::create($settings->get('twig.template_path'), $settings->get('twig.config'));
        if ($settings->get('debug')) {
            // Add extensions
            $twig->addExtension(new ProfilerExtension($profile));
            $twig->addExtension(new DebugExtension());
        }

        $twig->addExtension(new MarkdownExtension());
        $twig->addExtension(new FilesizeExtension());
        $twig->addRuntimeLoader(new class() implements \Twig\RuntimeLoader\RuntimeLoaderInterface {
            public function load($class): ?MarkdownRuntime
            {
                if ($class === MarkdownRuntime::class) {
                    // Provide the Markdown runtime with a default League/CommonMark-based implementation
                    return new MarkdownRuntime(new DefaultMarkdown());
                }

                return null;
            }
        });
        // Expose settings to Twig templates
        $twig->getEnvironment()->addGlobal('settings', $settings);
        return $twig;
    },
    Claire::class => static function (Connection $connection, Settings $settings, SessionInterface $session, AIProviderInterface $aiProvider): Claire {
        $brain = new Claire($connection, $settings, $session, $aiProvider);
        $brain->observe(new \App\Brain\Observability\Observer());
        return $brain;
    },
    Summary::class => static function (Connection $connection, Settings $settings, SessionInterface $session, AIProviderInterface $aiProvider, EmbeddingsProviderInterface $embeddingsProvider, VectorStoreInterface $vectorStore,): Summary {
        $summary = new Summary($connection, $settings, $session, $aiProvider, $embeddingsProvider, $vectorStore);
        $summary->observe(new \App\Brain\Observability\Observer());
        return $summary;
    },
    SessionManagerInterface::class => static fn (SessionInterface $session): SessionInterface => $session,
    SessionInterface::class => static fn (Settings $settings): PhpSession => new PhpSession($settings->get('session')),
    OidcClient::class => static fn (Settings $settings): OidcClient => new OidcClient($settings),
    Filesystem::class => static function (Settings $settings): FileSystem {
        if ($settings->get('files.fileSystem.type') === 'local') {
            $adapter = new League\Flysystem\Local\LocalFilesystemAdapter(
                $settings->get('files.fileSystem.path'),
            );
            return new League\Flysystem\Filesystem($adapter);
        }

        throw new Exception('Unknown filesystem type ' . $settings->get('files.fileSystem.type'));
    },
    AIProviderInterface::class => static fn(Settings $settings): AIProviderInterface => new OpenAILike(
        baseUri: $settings->get('llm.openai.baseUri'),
        key: $settings->get('llm.openai.key'),
        model: $settings->get('llm.openai.model')
    ),
    EmbeddingsProviderInterface::class => static fn(Settings $settings): EmbeddingsProviderInterface => new OpenAILikeEmbeddings(
        baseUri: $settings->get('llm.openai.baseUri') . '/embeddings',
        key: $settings->get('llm.openai.key'),
        model: $settings->get('llm.openai.modelEmbed')
    ),
    VectorStoreInterface::class => static fn(Settings $settings): VectorStoreInterface => new FileVectorStore(
        directory: $settings->get('llm.rag.path'),
        name: 'neuron-rag',
    ),
];
