<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\Settings;
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
    name: 'telegram:menu-button',
    description: 'Manage Telegram bot menu button to open WebApp'
)]
final class TelegramMenuButtonCommand extends Command
{
    private const string WEBAPP_PATH = '/telegram/webapp';
    private const string WEBAPP_TEXT = '⚙️ Options';


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
                name: 'set',
                mode: InputOption::VALUE_NONE,
                description: 'Set the menu button(use BASE_URL)'
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
        if ($input->getOption('set')) {
            return $this->setMenuButton($output);
        }

        if ($input->getOption('info')) {
            return $this->getMenuButtonInfo($output);
        }

        if ($input->getOption('delete')) {
            return $this->deleteMenuButton($output);
        }

        throw new InvalidArgumentException($this->getUsageHelp());
    }

    private function getUsageHelp(): string
    {
        return <<<'TEXT'
No WebApp URL or domain provided.

Usage examples:
  ./console telegram:set-menu-button --set
  ./console telegram:set-menu-button --info
  ./console telegram:set-menu-button --delete

Options:
  --set       Set the menu button
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

    private function setMenuButton(OutputInterface $output): int
    {
        $url = $this->settings->get('base_url') . self::WEBAPP_PATH;

        $result = $this->telegramBotApi->setChatMenuButton(
            menuButton: new MenuButtonWebApp(
                text: self::WEBAPP_TEXT,
                webApp: new WebAppInfo(url: $url),
            ),
        );

        if ($result !== true) {
            throw new \RuntimeException('Failed to set menu button: ' . $result->description);
        }

        $output->writeln('<info>Menu button set successfully:</info>');
        $output->writeln('  URL: ' . $url);
        $output->writeln('  Text: ' . self::WEBAPP_TEXT);

        $this->logger->info('Telegram menu button set', ['url' => $url]);

        return Command::SUCCESS;
    }
}
