<?php

declare(strict_types=1);

namespace App\Application\Dependency;

use App\Domain\Dependency\UpdateRequest;
use App\Domain\Dependency\UpdateRequestRepository;
use App\Domain\Dependency\UpdaterHeartbeat;
use App\Domain\Dependency\UpdateStatus;
use Psr\Log\LoggerInterface;

/**
 * The application's half of the conversation with the host-side agent.
 *
 * Three operations, in the order the agent performs them once a minute: say you are
 * here, take an order if there is one, report how it went. Nothing here executes
 * anything — the whole point of D-032 is that no container may, so the update itself
 * happens in a shell script outside every container and this class only moves rows.
 *
 * The agent reaches these through `docker compose exec backend php bin/console` rather
 * than over HTTP, which is why there is no authentication anywhere in sight: reaching
 * the console already requires the Docker socket on the host, and anyone holding that
 * has no need of an update endpoint. An HTTP route would have needed a second
 * authentication mechanism next to user sessions, guarding an operation whose real
 * protection is that it cannot be reached from the network at all.
 *
 * The heartbeat is separate from claiming on purpose. It is reported on every run,
 * including the overwhelmingly common one where there is nothing to do, because it is
 * the only evidence the panel has that an agent exists — and a panel that cannot tell
 * "no updates" from "no agent" offers a button that writes an order nobody will take.
 */
final readonly class UpdaterAgent
{
    /**
     * The shape an order id must have.
     *
     * Not a UUID pattern, although every id we write is a UUID: this is the set the
     * agent will accept on its command line (`ws:updater:finish <id>`), and it is
     * checked here as well so the two sides cannot drift into an id that this
     * application issues and the agent refuses — an order that could be claimed and
     * never closed, holding the dependency until the abandonment timeout cleared it.
     */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private UpdateRequestRepository $requests,
        private UpdaterHeartbeat $heartbeat,
        private DependencyCheckService $check,
        private LoggerInterface $logger,
    ) {
    }

    public function heartbeat(): void
    {
        $this->heartbeat->record(new \DateTimeImmutable());
    }

    /**
     * Takes one order, if there is one to take.
     *
     * The transition to `running` happens inside the repository in a single statement,
     * so two agent runs overlapping — normal, since the timer ticks faster than a
     * rebuild takes — cannot both get the same order. Were it a read followed by a
     * write, they could, and the palace would be rebuilt twice concurrently from two
     * different `.env` values.
     */
    public function claimNext(): ?UpdateRequest
    {
        $claimed = $this->requests->claimNext(new \DateTimeImmutable());

        if (null !== $claimed) {
            $this->logger->notice('Agent aktualizacji podjął zlecenie.', [
                'requestId' => $claimed->id,
                'name' => $claimed->name,
                'toVersion' => (string) $claimed->toVersion,
            ]);
        }

        return $claimed;
    }

    /**
     * Records the outcome and the log of one order.
     *
     * @param string $log the agent's whole run: backup, rebuild, restart, semantic
     *                    test. Kept verbatim and shown in the panel, because a failed
     *                    semantic test is the one failure that is otherwise completely
     *                    silent (D-003)
     *
     * @return bool false when nothing was waiting under that id — already closed by the
     *              abandonment timeout, or an id we never issued
     *
     * @throws \InvalidArgumentException when the id is not one we could ever have issued
     */
    public function finish(string $id, bool $succeeded, string $log): bool
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException(\sprintf(
                'Identyfikator zlecenia „%s” nie ma dopuszczalnej postaci.',
                $id,
            ));
        }

        $outcome = $succeeded ? UpdateStatus::Succeeded : UpdateStatus::Failed;
        $closed = $this->requests->finish($id, $outcome, $log, new \DateTimeImmutable());

        if (!$closed) {
            // Worth a warning rather than silence: the agent did something to the host
            // and the panel will not show the result of it.
            $this->logger->warning('Agent zgłosił wynik zlecenia, którego nie ma w stanie oczekującym na wynik.', [
                'requestId' => $id,
                'outcome' => $outcome->value,
            ]);

            return false;
        }

        $this->logger->notice('Agent aktualizacji zgłosił wynik zlecenia.', [
            'requestId' => $id,
            'outcome' => $outcome->value,
        ]);

        if ($succeeded) {
            $this->refreshAfterUpdate($id);
        }

        return true;
    }

    /**
     * Re-reads the versions once an update has succeeded.
     *
     * Without this the panel keeps showing the versions from the last check, which
     * after a successful update are the versions from **before** it: the update
     * finishes, the screen still says 3.7.0, and the only way to see the truth is to
     * press "Sprawdź teraz". Found by running a real 3.7.0 → 3.9.0 upgrade rather than
     * by any test, because every test asserted on rows this class writes, and this is
     * about a row it did not write.
     *
     * Failure here is logged and swallowed on purpose. The update itself succeeded and
     * that fact is already recorded; turning "we could not reach PyPI a second later"
     * into a failed order would report the opposite of what happened, and the next
     * scheduled check will correct the display anyway.
     */
    private function refreshAfterUpdate(string $id): void
    {
        try {
            $this->check->check();
        } catch (\Throwable $problem) {
            $this->logger->warning('Aktualizacja się powiodła, ale nie udało się odświeżyć wersji.', [
                'requestId' => $id,
                'problem' => $problem->getMessage(),
            ]);
        }
    }
}
