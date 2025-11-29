<?php

declare(strict_types=1);

use App\Agent\Brain;
use App\Services\OidcClient;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monolog\Logger;
use Odan\Session\PhpSession;
use Odan\Session\SessionInterface;
use Odan\Session\SessionManagerInterface;
use Slim\Views\Twig;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
        return $twig;
    },
    Brain::class => static function (Connection $connection, Settings $settings, SessionInterface $session): Brain {
        $brain = Brain::make(connection: $connection, settings: $settings, session: $session);
        $brain->observe(new \App\Agent\Observability\Observer());
        return $brain;
    },
    SessionManagerInterface::class => static fn (SessionInterface $session): \Odan\Session\SessionInterface => $session,
    SessionInterface::class => static fn (Settings $settings): \Odan\Session\PhpSession => new PhpSession($settings->get('session')),
    OidcClient::class => static fn (Settings $settings): OidcClient => new OidcClient($settings),
];
