<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\ChatMaintenance;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'chat:maintenance', description: 'SQL journal retention and generation diagnostics (dry-run by default)')]
final class ChatMaintenanceCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Exact authenticated user ID')
            ->addOption('thread', null, InputOption::VALUE_REQUIRED, 'Exact thread ID')
            ->addOption('reconcile', null, InputOption::VALUE_NONE, 'Mark proven orphan error, never replay')
            ->addOption('compact', null, InputOption::VALUE_NONE, 'Compact delivered Telegram journals')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Explicitly enable mutations')
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Completed body retention (1-36500 days)', '7')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'SQL page size / queue proof budget (1-10000)', '1000')
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, '0 or opaque sql:v1: keyset cursor', '0');
        $help = <<<'HELP'
Examples (no LLM, no response text or session secrets printed):
  ./console chat:maintenance --user USER --thread THREAD
  ./console chat:maintenance --user USER --thread THREAD --reconcile
  ./console chat:maintenance --user USER --thread THREAD --reconcile --apply --limit 10000
  ./console chat:maintenance --compact --retention-days 7 --limit 1000
  ./console chat:maintenance --compact --retention-days 7 --limit 1000 --apply --cursor 0

All actions are dry-run unless --apply is supplied. SQL compaction defaults to 7 days,
uses the (delivered, compacted, completed_at, id) index and keyset order completed_at, id.
Resume the returned sql:v1: cursor until 0. Numeric Redis SCAN cursors other than 0 are rejected.
Start a separate apply traversal at 0 after a dry-run. Busy/changed rows are skipped until
the next traversal. SQL retention never scans Redis and still works when Redis is unavailable.

Only delivered=true, attempted=true, fully confirmed journals with a known old completion
date and identity are compacted. DB time, not the operator clock, determines retention.
Completion dates use DB time and are never inferred from history. Permanent SQL tombstones
retain identity, dates and attempted/delivered barriers without TTL; only bodies become NULL.
The same global telegram-journal/id lock as execution and revision CAS protect the update.
Active, failed or ambiguous records retain their bodies for recovery and forensic inspection.
Retained outbox rows do NOT block delivered journal compaction: runtime checks delivered
before accessing response/checkpoints, so even a pending intent cannot replay a tombstone.

Diagnostics require BOTH user and thread. Reconciliation acquires the same ChatThreadLock
as execution, checks SQL journals and outbox, then atomically checks ALL Redis job payloads, including
pending, delayed, leased and dead. Any same-user/thread job blocks mutation, even for a
different message. Malformed/unknown payload identities also block mutation.
An undelivered SQL journal blocks only when its ID matches the current messageId and owner.
Active SQL outbox intents (pending/published/processing) block when their journal belongs to
this user/thread or its identity is still unknown. Known unrelated conversations do not block.
Completed/dead outbox receipts and older failed journals do not block a new Web orphan;
the full Redis queue proof is still required. No SQL response or payload is printed.
Job pointers are trusted only when jobMessageId matches messageId; stale pointers
are ignored and retained payloads are still fully scanned.
Only queued/running orphans become error with attempted=1, allowing a NEW message while
keeping the old message fenced. No re-enqueue, tools, LLM, history fetch, expiration or deletion.
Requires intact queue job hashes and all writers using the existing dispatch/lock protocol;
cannot prove absence of jobs already lost through external Redis deletion or eviction.
HELP;
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compact = (bool) $input->getOption('compact');
        $cursor = (string) $input->getOption('cursor');
        $user = (string) $input->getOption('user');
        $thread = (string) $input->getOption('thread');
        $reconcile = (bool) $input->getOption('reconcile');
        $apply = (bool) $input->getOption('apply');
        foreach (['limit', 'retention-days'] as $name) {
            if (preg_match('/^[0-9]+$/D', (string) $input->getOption($name)) !== 1) {
                $output->writeln('Invalid numeric option: ' . $name);
                return Command::INVALID;
            }
        }

        $limit = (int) $input->getOption('limit');
        $days = (int) $input->getOption('retention-days');
        if ($limit < 1 || $limit > 10000 || $days < 1 || $days > 36500) {
            $output->writeln('Limit must be 1-10000 and retention-days must be 1-36500.');
            return Command::INVALID;
        }

        if (($compact && ($user !== '' || $thread !== '' || $reconcile))
            || (! $compact && ($user === '' || $thread === '' || ($apply && ! $reconcile)))) {
            $output->writeln('Choose --compact OR --user USER --thread THREAD [--reconcile].');
            return Command::INVALID;
        }

        try {
            if ($compact) {
                ChatMaintenance::sqlCursor($cursor);
            } elseif ($cursor !== '0') {
                throw new \InvalidArgumentException('Invalid cursor');
            }
        } catch (\InvalidArgumentException) {
            $output->writeln('Invalid cursor for selected maintenance mode.');
            return Command::INVALID;
        }

        try {
            $service = $this->container->get(ChatMaintenance::class);
            $result = $compact
                ? $service->compact($days, $limit, $cursor, $apply)
                : $service->diagnose($user, $thread, $reconcile, $apply, $limit);
            if ($compact) {
                $result['counts'] = (object) $result['counts'];
            }

            $output->writeln(json_encode(['apply' => $apply, ...$result], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            if ($reconcile && $apply && ($result['result'] ?? '') !== 'reconciled') {
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Throwable) {
            // Driver exceptions may contain credentials or payloads. Never print them here.
            $output->writeln('Maintenance refused: lock busy, invalid options or storage unavailable.');
            return Command::FAILURE;
        }
    }
}
