<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Services\Session\SessionInterface;
use App\Services\Settings;
use Doctrine\DBAL\Connection;

trait Constructor
{
    public function __construct(
        protected Connection $connection,
        protected readonly Settings $settings,
        protected readonly SessionInterface $session,
    ) {
        parent::__construct();

        $this->observe(new \App\Brain\Observability\Observer());
    }
}
