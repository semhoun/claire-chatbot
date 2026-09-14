<?php

declare(strict_types=1);

namespace App\Console;

use Throwable;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use App\Sse\Daemon;

#[AsCommand(name: 'sse:serve', description: 'Run the ReactPHP SSE daemon', aliases: ['sse'])]
final class SseServeCommand extends Command
{
    public function __construct(
        private readonly Daemon $daemon,
        private readonly bool $isolatedBootstrap = false,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Interactive suggestions must not start SSE after the full application bootstrap.
        if (! $this->isolatedBootstrap) {
            $output->writeln('<error>SSE requires the isolated bootstrap; run ./console sse:serve.</error>');

            return Command::FAILURE;
        }

        try {
            $this->daemon->run();

            return Command::SUCCESS;
        } catch (Throwable) {
            // Do not expose credentials through Symfony's exception rendering.
            fwrite(STDERR, "SSE daemon failed; check configuration and local dependencies.\n");

            return Command::FAILURE;
        }
    }
}
