<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\Queue\RedisQueueBackend;
use App\Services\Queue\SqlOutboxQueueBackend;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:publish', description: 'Publish one bounded SQL outbox batch to Redis')]
final class QueuePublishCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Logical queue name; omit for all queues')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum receipts to examine (1-1000)', '100')
            ->setHelp('This command publishes immediately; it is not a dry-run. Workers also publish automatically.'
                . "\nIt never runs an agent itself or reactivates a terminal SQL receipt."
                . "\nUse the logical queue name, not the internal sql-outbox: transport prefix."
                . "\nThe JSON counters describe publication attempts, not completed Telegram deliveries."
                . "\nA failed Redis publication leaves its SQL intent pending for a later retry."
                . "\nExit status 1 means a publication or storage failure; some receipts may already be published.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (string) $input->getOption('limit');
        $queue = $input->getOption('queue');
        if (preg_match('/^[0-9]+$/D', $limit) !== 1 || (int) $limit < 1 || (int) $limit > 1000
            || ($queue !== null && (! is_string($queue) || trim($queue) === '' || strlen($queue) > 255
                || str_starts_with($queue, RedisQueueBackend::OUTBOX_QUEUE_PREFIX)))) {
            $output->writeln('Use a logical queue name and a limit between 1 and 1000.');
            return Command::INVALID;
        }

        try {
            $result = $this->container->get(SqlOutboxQueueBackend::class)->publishPending($queue, (int) $limit);
            $output->writeln(json_encode(['queue' => $queue, ...$result], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('Outbox publication failed; inspect the durable SQL receipts before retrying.');
            return Command::FAILURE;
        }
    }
}
