<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\OrphanFilePurger;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'files:purge-orphans', description: 'Purge orphan files and records (dry-run by default)')]
final class FilesPurgeOrphansCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Enable deletion')
            ->addOption('writers-stopped', null, InputOption::VALUE_NONE, 'Confirm uploads and workers are stopped')
            ->addOption('min-age-hours', null, InputOption::VALUE_REQUIRED, 'Minimum age (1-876000 hours)', '24');
        $this->setHelp(<<<'HELP'
Dry-run by default. Removes old file records with a missing user or a missing non-null
conversation, and unreferenced physical files under generated/ and the configured upload path.
Uploads without a conversation are valid. Telegram storage and other directories are not scanned.
Files and records less than 24 hours old are preserved by default.

  ./console files:purge-orphans
  ./console files:purge-orphans --apply --writers-stopped

Before applying, stop ALL file writers (HTTP uploads/generations and queue workers).
--writers-stopped is an operator confirmation, NOT a lock or an automatic shutdown.
The age threshold alone cannot protect an in-flight write. Keep writers stopped until completion.
Database records are removed first; a storage failure can leave physical files for a later run.
Failures stop the purge, may leave partial progress, and return a nonzero status. Rerunning is safe.
No conversation, user, Redis state or RAG/vector data is deleted by this command.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $age = (string) $input->getOption('min-age-hours');
        $apply = (bool) $input->getOption('apply');
        if (preg_match('/^[0-9]+$/D', $age) !== 1 || (int) $age < 1 || (int) $age > 876000) {
            $output->writeln('min-age-hours must be between 1 and 876000.');
            return Command::INVALID;
        }

        if ($apply && ! $input->getOption('writers-stopped')) {
            $output->writeln('Stop uploads and workers, then confirm with --writers-stopped.');
            return Command::INVALID;
        }

        try {
            $result = $this->container->get(OrphanFilePurger::class)->purge($apply, (int) $age);
            $output->writeln(json_encode(['apply' => $apply, ...$result], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('Purge failed: invalid storage configuration or database/filesystem error.'
                . ' Partial progress is possible.');
            return Command::FAILURE;
        }
    }
}
