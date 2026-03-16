<?php

declare(strict_types=1);

namespace App\Console;

use InvalidArgumentException;
use Monolog\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Phptg\BotApi\TelegramBotApi;

#[AsCommand(name: 'telegram:webhook', description: 'Configure Telegram webhook URL')]
final class TelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramBotApi $telegramBotApi,
        private readonly Logger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                name: 'url',
                mode: InputOption::VALUE_REQUIRED,
                description: 'The webhook URL to set (e.g., https://example.com/webhook/telegram)'
            )
            ->addOption(
                name: 'delete',
                mode: InputOption::VALUE_NONE,
                description: 'Remove the webhook instead of setting it'
            )
            ->addOption(
                name: 'info',
                mode: InputOption::VALUE_NONE,
                description: 'Get current webhook info'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getOption('info')) {
                return $this->getWebhookInfo($output);
            }

            if ($input->getOption('delete')) {
                return $this->deleteWebhook($output);
            }

            $url = $input->getOption('url');

            if (! is_string($url) || $url === '') {
                throw new InvalidArgumentException(
                    'Please provide a valid URL using --url option, or use --delete to remove the webhook, or --info to get current webhook info'
                );
            }

            return $this->setWebhook($url, $output);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram webhook command failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $output->writeln('<error>Failed to configure webhook: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function getWebhookInfo(OutputInterface $output): int
    {
        $webhookInfo = $this->telegramBotApi->getWebhookInfo();

        $output->writeln('<info>Current Webhook Info:</info>');
        $output->writeln('  URL: ' . ($webhookInfo->url !== null && $webhookInfo->url !== '' ? $webhookInfo->url : '(not set)'));
        $output->writeln('  Has Custom Certificate: ' . ($webhookInfo->hasCustomCertificate ? 'Yes' : 'No'));
        $output->writeln('  Pending Update Count: ' . $webhookInfo->pendingUpdateCount);

        if ($webhookInfo->lastErrorDate !== null) {
            $output->writeln('  Last Error Date: ' . date('Y-m-d H:i:s', $webhookInfo->lastErrorDate));
        }

        if ($webhookInfo->lastErrorMessage !== null && $webhookInfo->lastErrorMessage !== '') {
            $output->writeln('  Last Error Message: ' . $webhookInfo->lastErrorMessage);
        }

        if ($webhookInfo->maxConnections !== null) {
            $output->writeln('  Max Connections: ' . $webhookInfo->maxConnections);
        }

        if ($webhookInfo->ipAddress !== null && $webhookInfo->ipAddress !== '') {
            $output->writeln('  IP Address: ' . $webhookInfo->ipAddress);
        }

        return Command::SUCCESS;
    }

    private function deleteWebhook(OutputInterface $output): int
    {
        $this->telegramBotApi->deleteWebhook();
        $output->writeln('<info>Webhook deleted successfully.</info>');
        $this->logger->info('Telegram webhook deleted');

        return Command::SUCCESS;
    }

    private function setWebhook(string $url, OutputInterface $output): int
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL provided: ' . $url);
        }

        $this->telegramBotApi->setWebhook(url: $url);
        $output->writeln('<info>Webhook set successfully to: ' . $url . '</info>');
        $this->logger->info('Telegram webhook set', ['url' => $url]);

        return Command::SUCCESS;
    }
}
