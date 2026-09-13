<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Dependency\DependencyCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the dependency check now, from a shell.
 *
 * The scheduler already does this every six hours, so this is not how the check
 * normally happens. It exists for the two moments the scheduler is no help: right
 * after a deployment, when nobody wants to wait six hours to see whether the wiring
 * works at all, and while diagnosing a `check_problem` — reading the reason on a
 * terminal beats reading it through a panel that may itself be the thing that
 * is broken.
 *
 * Exits 0 even when the check failed. This is not a monitoring probe: "nie udało się
 * sprawdzić" is a result, it is recorded as one, and a non-zero exit would make
 * `docker compose exec` look like a broken command instead.
 */
#[AsCommand(
    name: 'ws:dependency:check',
    description: 'Sprawdza teraz, jaka wersja zależności działa i jaka jest najnowsza',
)]
final class CheckDependenciesCommand extends Command
{
    public function __construct(private readonly DependencyCheckService $dependencies)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->dependencies->check();
        $record = $status->record;

        $io->definitionList(
            ['Zależność' => $status->label],
            ['Działa' => (string) ($record->installed ?? '—')],
            ['Przypięta w .env' => (string) ($record->pinned ?? '—')],
            ['Najnowsza na PyPI' => (string) ($record->latest ?? '—')],
            ['Sprawdzono' => $record->checkedAt?->format('Y-m-d H:i:s P') ?? 'nigdy'],
        );

        if (null !== $record->checkProblem) {
            // Warning, not error: the previous numbers above still stand, and the
            // date next to them says how old they are.
            $io->warning($record->checkProblem);
        }

        if (null !== $record->installed
            && null !== $record->pinned
            && !$record->installed->equals($record->pinned)) {
            // Worth its own line: it means the image was not rebuilt after the
            // pin changed, and nothing else in the system would ever say so.
            $io->warning(\sprintf(
                'Działa %s, a w .env przypięto %s — obraz nie został przebudowany po zmianie przypięcia.',
                $record->installed,
                $record->pinned,
            ));
        }

        if ($record->updateAvailable()) {
            $io->note(\sprintf('Jest nowsza wersja: %s.', $record->latest));
        } elseif (null === $record->checkProblem) {
            $io->success('Nie ma nowszej wersji.');
        }

        return Command::SUCCESS;
    }
}
