<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\Settings;
use InvalidArgumentException;
use Phptg\BotApi\TelegramBotApi;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'telegram:webhook', description: 'Configure Telegram webhook URL')]
final class TelegramWebhookCommand extends Command
{
    private const string WEBHOOK_PATH = '/webhook/telegram';

    public function __construct(
        private readonly TelegramBotApi $telegramBotApi,
        private readonly Logger $logger,
        private readonly Settings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                name: 'url',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Full webhook URL (e.g., https://example.com/webhook/telegram)'
            )
            ->addOption(
                name: 'domain',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Domain name for webhook (path auto-generated, always HTTPS)'
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

            $urlOption = $input->getOption('url');
            $domainOption = $input->getOption('domain');

            if ((is_string($urlOption) && $urlOption !== '') && (is_string($domainOption) && $domainOption !== '')) {
                throw new InvalidArgumentException(
                    'Cannot use both --url and --domain options. Please use only one.'
                );
            }

            if (is_string($urlOption) && $urlOption !== '') {
                $url = $urlOption;
            } elseif (is_string($domainOption) && $domainOption !== '') {
                $url = $this->buildWebhookUrl($domainOption);
            } else {
                throw new InvalidArgumentException(
                    "No webhook URL or domain provided.\n\n" .
                    "Usage examples:\n" .
                    "  ./console telegram:webhook --domain=example.com\n" .
                    "  ./console telegram:webhook --url=https://example.com/webhook/telegram\n" .
                    "  ./console telegram:webhook --info\n" .
                    "  ./console telegram:webhook --delete\n\n" .
                    "Options:\n" .
                    "  --domain    Domain name only (HTTPS will be used, path auto-generated)\n" .
                    "  --url       Full webhook URL (for custom paths or protocols)\n" .
                    "  --info      Show current webhook status\n" .
                    '  --delete    Remove the webhook'
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

        $secretToken = $this->settings->get('telegram.webhook_secret');

        if (! is_string($secretToken) || $secretToken === '') {
            throw new InvalidArgumentException(
                'TELEGRAM_WEBHOOK_SECRET must be configured in environment to set webhook'
            );
        }

        $this->telegramBotApi->setWebhook(url: $url, secretToken: $secretToken);
        $output->writeln('<info>Webhook set successfully to: ' . $url . '</info>');
        $this->logger->info('Telegram webhook set', ['url' => $url]);

        return Command::SUCCESS;
    }

    private function buildWebhookUrl(string $domain): string
    {
        $domain = trim($domain);

        // Remove scheme if provided, we always use HTTPS with --domain
        if (str_starts_with($domain, 'http://')) {
            $domain = substr($domain, 7);
        } elseif (str_starts_with($domain, 'https://')) {
            $domain = substr($domain, 8);
        }

        return 'https://' . rtrim($domain, '/') . self::WEBHOOK_PATH;
    }
}
