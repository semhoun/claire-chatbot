<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\Settings;
use DateTimeImmutable;
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
    private const string WEBHOOK_PATH = '/telegram/webhook';

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
            return $this->dispatchCommand($input, $output);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram webhook command failed: ' . $e->getMessage(), ['exception' => $e]);
            $output->writeln('<error>Failed to configure webhook: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function dispatchCommand(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('info')) {
            return $this->getWebhookInfo($output);
        }

        if ($input->getOption('delete')) {
            return $this->deleteWebhook($output);
        }

        return $this->configureWebhook($input, $output);
    }

    private function configureWebhook(InputInterface $input, OutputInterface $output): int
    {
        $url = $this->resolveWebhookUrl($input);

        return $this->setWebhook($url, $output);
    }

    private function resolveWebhookUrl(InputInterface $input): string
    {
        $urlOption = $input->getOption('url');
        $domainOption = $input->getOption('domain');

        return $this->doResolveWebhookUrl($urlOption, $domainOption);
    }

    private function doResolveWebhookUrl(mixed $urlOption, mixed $domainOption): string
    {
        if ($this->isOptionSet($urlOption) && $this->isOptionSet($domainOption)) {
            throw new InvalidArgumentException('Cannot use both --url and --domain options. Please use only one.');
        }

        if ($this->isOptionSet($urlOption)) {
            return (string) $urlOption;
        }

        if ($this->isOptionSet($domainOption)) {
            return $this->buildWebhookUrl((string) $domainOption);
        }

        throw new InvalidArgumentException($this->getUsageHelp());
    }

    private function isOptionSet(mixed $option): bool
    {
        return is_string($option) && $option !== '';
    }

    private function getUsageHelp(): string
    {
        return <<<'TEXT'
No webhook URL or domain provided.

Usage examples:
  ./console telegram:webhook --domain=example.com
  ./console telegram:webhook --url=https://example.com/webhook/telegram
  ./console telegram:webhook --info
  ./console telegram:webhook --delete

Options:
  --domain    Domain name only (HTTPS will be used, path auto-generated)
  --url       Full webhook URL (for custom paths or protocols)
  --info      Show current webhook status
  --delete    Remove the webhook
TEXT;
    }

    private function getWebhookInfo(OutputInterface $output): int
    {
        $info = $this->telegramBotApi->getWebhookInfo();

        $output->writeln('<info>Current Webhook Info:</info>');
        $this->writeBasicInfo($output, $info);
        $this->writeErrorInfo($output, $info);
        $this->writeOptionalInfo($output, $info);

        return Command::SUCCESS;
    }

    private function writeBasicInfo(OutputInterface $output, mixed $info): void
    {
        $url = $info->url !== null && $info->url !== '' ? $info->url : '(not set)';
        $output->writeln('  URL: ' . $url);
        $output->writeln('  Has Custom Certificate: ' . ($info->hasCustomCertificate ? 'Yes' : 'No'));
        $output->writeln('  Pending Update Count: ' . $info->pendingUpdateCount);
    }

    private function writeErrorInfo(OutputInterface $output, mixed $info): void
    {
        if ($info->lastErrorDate !== null) {
            $date = DateTimeImmutable::createFromFormat('U', (string) $info->lastErrorDate)->format('Y-m-d H:i:s');
            $output->writeln('  Last Error Date: ' . $date);
        }

        if ($info->lastErrorMessage !== null && $info->lastErrorMessage !== '') {
            $output->writeln('  Last Error Message: ' . $info->lastErrorMessage);
        }
    }

    private function writeOptionalInfo(OutputInterface $output, mixed $info): void
    {
        if ($info->maxConnections !== null) {
            $output->writeln('  Max Connections: ' . $info->maxConnections);
        }

        if ($info->ipAddress !== null && $info->ipAddress !== '') {
            $output->writeln('  IP Address: ' . $info->ipAddress);
        }
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
