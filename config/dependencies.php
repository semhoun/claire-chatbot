<?php

declare(strict_types=1);

use App\Agent\Brain;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Monolog\Logger;
use NeuronAI\Observability\LogObserver;
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
        if ($settings->get('debug')) {
            $queryCache = new ArrayAdapter();
            $metadataCache = new ArrayAdapter();
        } else {
            $queryCache = new PhpFilesAdapter('queries', 0, $settings->get('cache_dir'));
            $metadataCache = new PhpFilesAdapter('metadata', 0, $settings->get('cache_dir'));
        }

        $config = new Configuration();
        $config->setMetadataCache($metadataCache);

        $driverImpl = new AttributeDriver($settings->get('database.doctrine.entity_path'), true);
        $config->setMetadataDriverImpl($driverImpl);
        $config->setQueryCache($queryCache);
        $config->setProxyDir($settings->get('cache_dir') . '/proxy');
        $config->setProxyNamespace('App\Proxies');

        if ($settings->get('debug')) {
            $config->setAutoGenerateProxyClasses(true);
        } else {
            $config->setAutoGenerateProxyClasses(false);
        }

        return $config;
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
    Brain::class => static function (Settings $settings, Logger $logger, Connection $connection, SessionInterface $session): Brain {
        $brain = Brain::make($connection, $settings, $session->get('chatId'));
        $brain->observe(new LogObserver($logger));
        return $brain;
    },
    SessionManagerInterface::class => static fn (SessionInterface $session): \Odan\Session\SessionInterface => $session,
    SessionInterface::class => static fn (Settings $settings): \Odan\Session\PhpSession => new PhpSession($settings->get('session')),
];
