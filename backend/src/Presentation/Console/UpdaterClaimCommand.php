<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Dependency\UpdaterAgent;
use App\Domain\Dependency\UpdateRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hands the host-side agent one order to carry out, if there is one.
 *
 * The output contract is narrow because a shell script parses it (`scripts/aktualizator.sh`):
 * either exactly one JSON object on standard output, or nothing at all. Nothing is the
 * ordinary case — the timer ticks every minute and updates happen a few times a year —
 * so it exits 0 either way. A non-zero exit for "no work" would light `systemctl
 * status` red all year and train whoever watches it to ignore the colour.
 *
 * Which is also why this prints no banner, no table and no blank line: every byte on
 * standard output is input to `jq`. Anything diagnostic belongs in the application log,
 * where UpdaterAgent puts it.
 *
 * Claiming is a write — it moves the order to `running` and stamps `started_at`. That
 * is deliberate and it is why the agent's dry run does not call this command: an order
 * marked as taken and never executed would sit in the panel as an update in progress
 * until the abandonment timeout closed it 45 minutes later.
 */
#[AsCommand(
    name: 'ws:updater:claim',
    description: 'Wypisuje jedno zlecenie aktualizacji do wykonania (JSON) albo nic',
)]
final class UpdaterClaimCommand extends Command
{
    public function __construct(private readonly UpdaterAgent $agent)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $claimed = $this->agent->claimNext();

        if (null === $claimed) {
            return Command::SUCCESS;
        }

        // `write`, not `writeln`-with-a-blank-line, and no styling helper: the agent
        // feeds this straight to `jq`. JSON_THROW_ON_ERROR because a silent `false`
        // here would print the word "nothing" as an empty line, and the agent would
        // read that as "no work" while the order sat claimed.
        $output->writeln(json_encode(
            self::shapeOf($claimed),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }

    /**
     * The four fields the agent reads, and no more.
     *
     * Not UpdateRequest::toArray(): that one is the panel's shape, with a display name
     * and timestamps in it. The agent needs an id to report back under, a name to
     * check it can handle, and the two versions — one to install, one to mention when
     * it disagrees with `.env`. Sending it anything else would tie a shell script to a
     * shape that changes when a screen changes.
     *
     * @return array{id: string, name: string, fromVersion: string|null, toVersion: string}
     */
    private static function shapeOf(UpdateRequest $request): array
    {
        return [
            'id' => $request->id,
            'name' => $request->name,
            // Null rather than an empty string when we never learned it: the agent
            // reads it with `jq -r '.fromVersion // empty'` and treats both as "not
            // told", and null is the honest one.
            'fromVersion' => null === $request->fromVersion ? null : (string) $request->fromVersion,
            'toVersion' => (string) $request->toVersion,
        ];
    }
}
