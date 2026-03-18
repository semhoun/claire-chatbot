<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;

trait Constructor
{
    protected readonly Settings $settings;
    protected readonly Connection $connection;

    public function __construct(
        protected readonly ContainerInterface $container,
        protected readonly SessionInterface $session,
    ) {

        $this->settings = $this->container->get(Settings::class);
        $this->connection = $this->container->get(Connection::class);

        parent::__construct();

        $this->observe(new \App\Brain\Observability\Observer());
    }
}
