<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\Settings;
use InvalidArgumentException;
use Monolog\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Telegram\Bot\Api;

#[AsCommand(name: 'telegram:webhook', description: 'Configure Telegram webhook URL')]
final class TelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly Api $api,
        private readonly Logger $logger,
        private readonly Settings $settings
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
        $webhookInfo = $this->api->getWebhookInfo();

        $output->writeln('<info>Current Webhook Info:</info>');
        $output->writeln('  URL: ' . ($webhookInfo->url ?: '(not set)'));
        $output->writeln('  Has Custom Certificate: ' . ($webhookInfo->has_custom_certificate ? 'Yes' : 'No'));
        $output->writeln('  Pending Update Count: ' . $webhookInfo->pending_update_count);

        if ($webhookInfo->last_error_date !== null) {
            $output->writeln('  Last Error Date: ' . date('Y-m-d H:i:s', $webhookInfo->last_error_date));
        }

        if ($webhookInfo->last_error_message !== null && $webhookInfo->last_error_message !== '') {
            $output->writeln('  Last Error Message: ' . $webhookInfo->last_error_message);
        }

        if ($webhookInfo->max_connections !== null) {
            $output->writeln('  Max Connections: ' . $webhookInfo->max_connections);
        }

        if ($webhookInfo->ip_address !== null && $webhookInfo->ip_address !== '') {
            $output->writeln('  IP Address: ' . $webhookInfo->ip_address);
        }

        return Command::SUCCESS;
    }

    private function deleteWebhook(OutputInterface $output): int
    {
        $this->api->deleteWebhook();
        $output->writeln('<info>Webhook deleted successfully.</info>');
        $this->logger->info('Telegram webhook deleted');

        return Command::SUCCESS;
    }

    private function setWebhook(string $url, OutputInterface $output): int
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL provided: ' . $url);
        }

        $this->api->setWebhook(['url' => $url]);
        $output->writeln('<info>Webhook set successfully to: ' . $url . '</info>');
        $this->logger->info('Telegram webhook set', ['url' => $url]);

        return Command::SUCCESS;
    }
}
