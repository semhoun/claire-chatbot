<?php

declare(strict_types=1);

namespace App\Console;

use InvalidArgumentException;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\MenuButtonWebApp;
use Phptg\BotApi\Type\WebAppInfo;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'telegram:set-menu-button',
    description: 'Set Telegram bot menu button to open WebApp'
)]
final class TelegramSetMenuButtonCommand extends Command
{
    private const string WEBAPP_PATH = '/telegram/webapp';

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
                description: 'Full WebApp URL (e.g., https://example.com/telegram/webapp)'
            )
            ->addOption(
                name: 'domain',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Domain name for WebApp (path auto-generated, always HTTPS)'
            )
            ->addOption(
                name: 'delete',
                mode: InputOption::VALUE_NONE,
                description: 'Remove the menu button and restore default'
            )
            ->addOption(
                name: 'info',
                mode: InputOption::VALUE_NONE,
                description: 'Get current menu button info'
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
            $this->logger->error('Telegram menu button command failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $output->writeln('<error>Failed to configure menu button: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    private function dispatchCommand(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('info')) {
            return $this->getMenuButtonInfo($output);
        }

        if ($input->getOption('delete')) {
            return $this->deleteMenuButton($output);
        }

        return $this->configureMenuButton($input, $output);
    }

    private function configureMenuButton(InputInterface $input, OutputInterface $output): int
    {
        $url = $this->resolveWebAppUrl($input);

        return $this->setMenuButton($url, $output);
    }

    private function resolveWebAppUrl(InputInterface $input): string
    {
        $urlOption = $input->getOption('url');
        $domainOption = $input->getOption('domain');

        return $this->doResolveWebAppUrl($urlOption, $domainOption);
    }

    private function doResolveWebAppUrl(mixed $urlOption, mixed $domainOption): string
    {
        if ($this->isOptionSet($urlOption) && $this->isOptionSet($domainOption)) {
            throw new InvalidArgumentException(
                'Cannot use both --url and --domain options. Please use only one.'
            );
        }

        if ($this->isOptionSet($urlOption)) {
            return (string) $urlOption;
        }

        if ($this->isOptionSet($domainOption)) {
            return $this->buildWebAppUrl((string) $domainOption);
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
No WebApp URL or domain provided.

Usage examples:
  ./console telegram:set-menu-button --domain=example.com
  ./console telegram:set-menu-button --url=https://example.com/telegram/webapp
  ./console telegram:set-menu-button --info
  ./console telegram:set-menu-button --delete

Options:
  --domain    Domain name only (HTTPS will be used, path auto-generated)
  --url       Full WebApp URL (for custom paths or protocols)
  --info      Show current menu button status
  --delete    Remove the custom menu button and restore default
TEXT;
    }

    private function getMenuButtonInfo(OutputInterface $output): int
    {
        $menuButton = $this->telegramBotApi->getChatMenuButton();

        $output->writeln('<info>Current Menu Button Info:</info>');
        $output->writeln('  Type: ' . $menuButton->getType());

        if ($menuButton instanceof MenuButtonWebApp) {
            $output->writeln('  Text: ' . $menuButton->text);
            $output->writeln('  WebApp URL: ' . $menuButton->webApp->url);
        }

        return Command::SUCCESS;
    }

    private function deleteMenuButton(OutputInterface $output): int
    {
        $this->telegramBotApi->setChatMenuButton();
        $output->writeln('<info>Menu button reset to default successfully.</info>');
        $this->logger->info('Telegram menu button reset to default');

        return Command::SUCCESS;
    }

    private function setMenuButton(string $url, OutputInterface $output): int
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL provided: ' . $url);
        }

        $result = $this->telegramBotApi->setChatMenuButton(
            menuButton: new MenuButtonWebApp(
                text: 'Paramètres',
                webApp: new WebAppInfo(url: $url),
            ),
        );

        if ($result !== true) {
            throw new \RuntimeException('Failed to set menu button: ' . $result->description);
        }

        $output->writeln('<info>Menu button set successfully:</info>');
        $output->writeln('  URL: ' . $url);
        $output->writeln('  Text: Paramètres');

        $this->logger->info('Telegram menu button set', ['url' => $url]);

        return Command::SUCCESS;
    }

    private function buildWebAppUrl(string $domain): string
    {
        $domain = trim($domain);

        // Remove scheme if provided, we always use HTTPS with --domain
        if (str_starts_with($domain, 'http://')) {
            $domain = substr($domain, 7);
        } elseif (str_starts_with($domain, 'https://')) {
            $domain = substr($domain, 8);
        }

        return 'https://' . rtrim($domain, '/') . self::WEBAPP_PATH;
    }
}
