<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\Queue\QueueWorker;
use App\Services\Queue\QueueWorkerOptions;
use App\Services\Settings;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Run the Redis queue worker')]
final class QueueWorkCommand extends Command
{
    // Not in __Construct because redis could not be available at this time
    private ?QueueWorker $queueWorker = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name to process')
            ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in seconds for BRPOP operation')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process a single job and exit')
            ->addOption('max-jobs', null, InputOption::VALUE_OPTIONAL, 'Maximum jobs to process before exiting', 100)
            ->addOption('max-time', null, InputOption::VALUE_OPTIONAL, 'Maximum runtime in seconds before exiting', 3600);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->queueWorker = $this->container->get(QueueWorker::class);
        $this->setupSignalHandlers();

        $workerId = $this->generateWorkerId();
        $queueWorkerOptions = $this->createWorkerOptions($input);

        $output->writeln(sprintf('<info>Starting queue worker %s on queue %s</info>', $workerId, $queueWorkerOptions->queueName));

        $processedJobs = $this->queueWorker->run($queueWorkerOptions, $workerId, $output);

        $output->writeln(sprintf('<info>Queue worker stopped after %d job(s)</info>', $processedJobs));

        return Command::SUCCESS;
    }

    private function generateWorkerId(): string
    {
        $hostname = gethostname();
        $host = is_string($hostname) && $hostname !== '' ? $hostname : 'localhost';

        return sprintf('%s:%d', $host, getmypid());
    }

    private function createWorkerOptions(InputInterface $input): QueueWorkerOptions
    {
        $queueName = $input->getOption('queue');
        $timeout = $input->getOption('timeout');

        return new QueueWorkerOptions(
            queueName: $this->resolveQueueName($queueName),
            timeout: $this->resolveTimeout($timeout),
            once: (bool) $input->getOption('once'),
            maxJobs: max(0, (int) $input->getOption('max-jobs')),
            maxTime: max(0, (int) $input->getOption('max-time')),
        );
    }

    private function resolveQueueName(mixed $queueName): string
    {
        return is_string($queueName) && $queueName !== ''
            ? $queueName
            : (string) $this->settings->get('queue.defaultQueue');
    }

    private function resolveTimeout(mixed $timeout): int
    {
        return max(1, is_numeric($timeout) ? (int) $timeout : (int) $this->settings->get('queue.timeout'));
    }

    private function setupSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->logger->warning('pcntl extension not available, signal handling disabled');

            return;
        }

        pcntl_signal(SIGTERM, function (): void {
            $this->logger->info('SIGTERM received, shutting down queue worker gracefully');
            $this->queueWorker->requestStop();
        });

        pcntl_signal(SIGINT, function (): void {
            $this->logger->info('SIGINT received, shutting down queue worker gracefully');
            $this->queueWorker->requestStop();
        });
    }
}
