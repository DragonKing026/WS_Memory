<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Dependency\UpdaterAgent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "I am here." Called by the host-side agent on every run.
 *
 * The pulse is the only evidence the panel has that an agent exists at all — no
 * container may look at the host (D-032), so the answer to "is there anything able to
 * carry an update out" can only come from the agent saying so. Which is why it is
 * reported on every run, including the overwhelmingly common one where there is no
 * work: a panel that cannot tell "nothing to update" from "nobody to update with"
 * offers a button that writes an order nobody will ever take.
 *
 * Prints nothing. The exit code is the whole contract the agent relies on, and output
 * on a path that runs once a minute forever would only fill a journal.
 */
#[AsCommand(
    name: 'ws:updater:heartbeat',
    description: 'Zgłasza, że agent aktualizacji na hoście działa',
)]
final class UpdaterHeartbeatCommand extends Command
{
    public function __construct(private readonly UpdaterAgent $agent)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->agent->heartbeat();

        return Command::SUCCESS;
    }
}
