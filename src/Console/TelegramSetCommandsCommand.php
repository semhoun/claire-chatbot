<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\TelegramService;
use Psr\Log\LoggerInterface as Logger;
use Phptg\BotApi\TelegramBotApi;
use Phptg\BotApi\Type\BotCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'telegram:set-commands',
    description: 'Set Telegram bot commands menu (SetMyCommands)'
)]
final class TelegramSetCommandsCommand extends Command
{
    public function __construct(
        private readonly TelegramBotApi $telegramBotApi,
        private readonly Logger $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $commands = [];

            foreach (TelegramService::COMMANDS as $command => $description) {
                $commands[] = new BotCommand(
                    command: $command,
                    description: $description,
                );
            }

            $result = $this->telegramBotApi->setMyCommands(commands: $commands);

            if ($result === true) {
                $output->writeln('<info>Telegram bot commands set successfully:</info>');

                foreach (TelegramService::COMMANDS as $command => $description) {
                    $output->writeln(sprintf('  /%s - %s', $command, $description));
                }

                $this->logger->info('Telegram bot commands updated', [
                    'commands' => array_keys(TelegramService::COMMANDS),
                ]);

                return Command::SUCCESS;
            }

            $output->writeln('<error>Failed to set commands: ' . $result->description . '</error>');

            return Command::FAILURE;
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to set Telegram commands: ' . $throwable->getMessage(), [
                'exception' => $throwable,
            ]);
            $output->writeln('<error>Failed to set commands: ' . $throwable->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
