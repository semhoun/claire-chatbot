<?php

declare(strict_types=1);

namespace App\Console;

use App\Queue\QueueDispatcherInterface;
use App\Services\TelegramService;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\Update;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'telegram:daemon',
    description: 'Run Telegram bot in daemon mode (polling)'
)]
final class TelegramDaemonCommand extends Command
{
    private bool $running = true;

    private QueueDispatcherInterface $queueDispatcher;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly TelegramBotApi $telegramBotApi,
        private readonly Logger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'timeout',
                't',
                InputOption::VALUE_OPTIONAL,
                'Polling timeout in seconds',
                30
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Number of updates to fetch per request',
                100
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $this->queueDispatcher = $this->container->get(QueueDispatcherInterface::class);
        $this->setupSignalHandlers();

        if (! $this->initializeBot($output)) {
            return Command::FAILURE;
        }

        $this->pollingLoop($input, $output);

        $output->writeln('');
        $output->writeln('<info>Telegram daemon stopped gracefully</info>');

        return Command::SUCCESS;
    }

    private function initializeBot(OutputInterface $output): bool
    {
        try {
            $botInfo = $this->telegramBotApi->getMe();
            $output->writeln(sprintf('<info>Starting Telegram daemon for bot: @%s</info>', $botInfo->username));
            $output->writeln('<comment>Press Ctrl+C to stop</comment>');
            $output->writeln('');

            return true;
        } catch (\Throwable $throwable) {
            $output->writeln(sprintf('<error>Failed to get bot info: %s</error>', $throwable->getMessage()));

            return false;
        }
    }

    private function pollingLoop(InputInterface $input, OutputInterface $output): void
    {
        $timeout = (int) $input->getOption('timeout');
        $limit = (int) $input->getOption('limit');
        $offset = 0;

        while ($this->running) {
            try {
                $updates = $this->telegramBotApi->getUpdates(offset: $offset, limit: $limit, timeout: $timeout);
                $offset = $this->processUpdates($updates, $offset);
            } catch (\Throwable $throwable) {
                $this->handlePollingError($throwable, $output);
            }

            if ($this->running) {
                sleep(1);
            }
        }
    }

    /**
     * @param array<Update> $updates
     */
    private function processUpdates(array $updates, int $offset): int
    {
        foreach ($updates as $update) {
            $offset = max($offset, $update->updateId + 1);
            $this->dispatchUpdate($update);
        }

        return $offset;
    }

    private function dispatchUpdate(Update $update): void
    {
        $updatePayload = json_decode(json_encode($update, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->queueDispatcher->dispatch(
            TelegramService::class,
            ['update_json' => json_encode($updatePayload, JSON_THROW_ON_ERROR)],
        );
    }

    private function handlePollingError(\Throwable $throwable, OutputInterface $output): void
    {
        $this->logger->error('Telegram polling error: ' . $throwable->getMessage(), ['exception' => $throwable]);
        $output->writeln(sprintf('<error>Polling error: %s</error>', $throwable->getMessage()));
    }

    private function setupSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->logger->debug('pcntl extension not available, signal handling disabled');

            return;
        }

        pcntl_signal(SIGTERM, function (): void {
            $this->logger->info('SIGTERM received, shutting down gracefully...');
            $this->running = false;
        });

        pcntl_signal(SIGINT, function (): void {
            $this->logger->info('SIGINT received, shutting down gracefully...');
            $this->running = false;
        });
    }
}
