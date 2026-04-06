<?php

declare(strict_types=1);

namespace App\Console;

use App\Queue\QueueWorker;
use App\Queue\QueueWorkerOptions;
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
    private ?QueueWorker $queueWorker;

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
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Sleep time in seconds when no job is available')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process a single job and exit')
            ->addOption('max-jobs', null, InputOption::VALUE_OPTIONAL, 'Maximum jobs to process before exiting', 100)
            ->addOption('max-time', null, InputOption::VALUE_OPTIONAL, 'Maximum runtime in seconds before exiting', 3600);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->queueWorker = $this->container->get(QueueWorker::class);

        $this->setupSignalHandlers();

        $workerId = sprintf('%s:%d', gethostname() ?: 'localhost', getmypid());
        $options = new QueueWorkerOptions(
            queueName: (string) ($input->getOption('queue') ?: $this->settings->get('queue.defaultQueue')),
            sleep: max(1, (int) ($input->getOption('sleep') ?: $this->settings->get('queue.sleep'))),
            once: (bool) $input->getOption('once'),
            maxJobs: max(0, (int) $input->getOption('max-jobs')),
            maxTime: max(0, (int) $input->getOption('max-time')),
        );

        $output->writeln(sprintf('<info>Starting queue worker %s on queue %s</info>', $workerId, $options->queueName));

        $processedJobs = $this->queueWorker->run($options, $workerId, $output);

        $output->writeln(sprintf('<info>Queue worker stopped after %d job(s)</info>', $processedJobs));

        return Command::SUCCESS;
    }

    private function setupSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->logger->debug('pcntl extension not available, signal handling disabled');

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
