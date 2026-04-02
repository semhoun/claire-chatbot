<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;

trait Constructor
{
    protected readonly Settings $settings;
    protected readonly Connection $connection;
    protected readonly Logger $logger;

    public function __construct(
        protected readonly ContainerInterface $container,
        protected readonly SessionInterface $session,
    ) {
        $this->settings = $this->container->get(Settings::class);
        $this->connection = $this->container->get(Connection::class);
        $this->logger = $this->container->get(Logger::class);

        parent::__construct();

        $this->observe(new \App\Brain\Observability\Observer());
        $this->observe(new \App\Brain\Event\TimestampObserver());
    }
}
