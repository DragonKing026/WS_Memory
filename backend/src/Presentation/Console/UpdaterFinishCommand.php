<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Dependency\UpdaterAgent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Records how an update went, as reported by the host-side agent.
 *
 * The log arrives on standard input rather than as an argument, and that is not a
 * stylistic choice: it is the entire output of a backup, an image rebuild, a container
 * restart and the semantic test — kilobytes, with newlines and quotes in them. As an
 * argument it would hit the command-line length limit, and every shell metacharacter in
 * a Python traceback would become a quoting problem in the agent. A pipe has neither.
 *
 * That log is the point of the whole endpoint. A failed semantic test is silent
 * (D-003): the palace answers, writes succeed, and only Polish queries stop finding
 * Polish content. The agent runs the test and rolls back, but the trace of WHY is this
 * text, shown in the panel — without it, an administrator sees a failed update with
 * nothing to read.
 *
 * Exit codes are the agent's contract: 0 recorded, 1 nothing was waiting under that id
 * (so the agent keeps a copy of the log on the host, since the panel will not show it),
 * 2 the invocation itself was wrong.
 */
#[AsCommand(
    name: 'ws:updater:finish',
    description: 'Zapisuje wynik zlecenia aktualizacji; dziennik czytany ze standardowego wejścia',
)]
final class UpdaterFinishCommand extends Command
{
    private const SUCCEEDED = 'succeeded';
    private const FAILED = 'failed';

    public function __construct(private readonly UpdaterAgent $agent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Identyfikator zlecenia z ws:updater:claim')
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                \sprintf('Wynik wykonania: %s albo %s', self::SUCCEEDED, self::FAILED),
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Everything this command says goes to standard error, because the agent sends
        // standard output to /dev/null and appends standard error to the order's log
        // (scripts/aktualizator.sh). A complaint written to stdout would be discarded at
        // exactly the moment somebody needs to read it.
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $status = $input->getOption('status');

        if (self::SUCCEEDED !== $status && self::FAILED !== $status) {
            // Refused rather than defaulted. Guessing `failed` would mark a successful
            // update as broken; guessing `succeeded` would hide a broken one. Both are
            // worse than making the agent say which it meant.
            $errors->writeln(\sprintf(
                '<error>Wymagane --status=%s albo --status=%s.</error>',
                self::SUCCEEDED,
                self::FAILED,
            ));

            return Command::INVALID;
        }

        $id = $input->getArgument('id');
        if (!\is_string($id)) {
            $errors->writeln('<error>Identyfikator zlecenia musi być pojedynczą wartością.</error>');

            return Command::INVALID;
        }

        try {
            $recorded = $this->agent->finish($id, self::SUCCEEDED === $status, $this->logFrom($input));
        } catch (\InvalidArgumentException $e) {
            $errors->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        if (!$recorded) {
            // The agent has already changed the host by the time it reports, so this is
            // the worst outcome in the whole flow: something happened and the panel
            // will not say what. The agent answers it by keeping the log on disk, so
            // the message names the situation rather than merely failing.
            $errors->writeln(\sprintf(
                '<error>Zlecenie %s nie oczekuje na wynik (nie ma go albo zostało już domknięte).</error>',
                $id,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Everything on standard input, or an empty log when there is no input to read.
     *
     * The terminal check is what keeps a hand-typed invocation from hanging forever on
     * a read that nobody is going to satisfy — the one situation where "no log" is the
     * right answer rather than something to wait for.
     */
    private function logFrom(InputInterface $input): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        if (null === $stream) {
            $opened = fopen('php://stdin', 'r');

            if (false === $opened || stream_isatty($opened)) {
                return '';
            }

            $stream = $opened;
        }

        $contents = stream_get_contents($stream);

        return false === $contents ? '' : $contents;
    }
}
