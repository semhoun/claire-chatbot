<?php

declare(strict_types=1);

use App\Services\Settings;
use App\Services\Brain;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Odan\Session\PhpSession;
use Odan\Session\SessionInterface;
use Odan\Session\SessionManagerInterface;
use Slim\Views\Twig;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Twig\Extension\DebugExtension;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;
use Twig\Extra\Markdown\DefaultMarkdown;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;

return [
    // Doctrine Dbal connection
    Connection::class => static fn (Settings $settings, Doctrine\ORM\Configuration $conf): Doctrine\DBAL\Connection => DriverManager::getConnection($settings->get('doctrine.connection'), $conf),
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
        $driverImpl = new AttributeDriver($settings->get('doctrine.entity_path'), true);
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
    EntityManager::class => static fn (Configuration $config, Connection $connection): EntityManager => new EntityManager($connection, $config),
    EntityManagerInterface::class => DI\get(EntityManager::class),
    // Settings.
    Settings::class => DI\factory([Settings::class, 'load']),
    Logger::class => static function (Settings $settings): Logger {
        $logger = new Logger($settings->get('logger.name'));
        $processor = new UidProcessor();
        $logger->pushProcessor($processor);

        $handler = new StreamHandler($settings->get('logger.path'), $settings->get('logger.level'));
        $logger->pushHandler($handler);

        return $logger;
    },
    Twig::class => static function (Settings $settings, Profile $profile): Twig {
        $view = Twig::create($settings->get('view.template_path'), $settings->get('view.twig'));
        if ($settings->get('debug')) {
            // Add extensions
            $view->addExtension(new ProfilerExtension($profile));
            $view->addExtension(new DebugExtension());
        }
        $view->addExtension(new MarkdownExtension());
        $view->addRuntimeLoader(new class implements \Twig\RuntimeLoader\RuntimeLoaderInterface {
            public function load($class) {
                if (MarkdownRuntime::class === $class) {
                    // Provide the Markdown runtime with a default League/CommonMark-based implementation
                    return new MarkdownRuntime(new DefaultMarkdown());
                }
                return null;
            }
        });
        return $view;
    },
    Brain::class => static function (Settings $settings): Brain {
        $brain = Brain::make();
        $brain->config($settings);
        return $brain;
    },
    SessionManagerInterface::class => static function (SessionInterface $sessionInterface) {
        return $sessionInterface;
    },
    SessionInterface::class => static function (Settings $settings) {
        return new PhpSession($settings->get('session'));
    },
];
