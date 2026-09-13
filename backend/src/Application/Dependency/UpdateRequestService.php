<?php

declare(strict_types=1);

namespace App\Application\Dependency;

use App\Domain\Audit\AuditTrail;
use App\Domain\Dependency\CheckFailed;
use App\Domain\Dependency\DependencyStateStore;
use App\Domain\Dependency\DependencyStatus;
use App\Domain\Dependency\DependencyView;
use App\Domain\Dependency\ReleaseCatalog;
use App\Domain\Dependency\UpdateRefused;
use App\Domain\Dependency\UpdateRequest;
use App\Domain\Dependency\UpdateRequestRepository;
use App\Domain\Dependency\UpdaterHeartbeat;
use App\Domain\Dependency\UpdaterState;
use App\Domain\Dependency\UpdateStatus;
use App\Domain\Dependency\Version;
use App\Domain\Identity\Actor;
use App\Domain\Identity\AuthorDirectory;
use App\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Ordering an update, and reading back what has been ordered.
 *
 * The backend never updates anything. It writes a row, and a script on the host — the
 * only thing here allowed near Docker — picks it up (D-032). Which means this class is
 * not a form handler: it is the gate between an HTTP request from a browser and a
 * command that will run on the machine, and every refusal below exists because of what
 * is on the other side of that gate.
 *
 * **The version is the dangerous field.** It ends up in `.env` and from there in
 * `pip install mempalace==<version>`. Two things are done about that, and neither is
 * redundant. First the spelling is checked against a pattern narrow enough that the
 * accepted set contains nothing to escape out of — three groups of digits, two dots,
 * end of string. Then the value is checked against the release catalogue, because a
 * well-formed version that does not exist is not an attack but is still a rebuild that
 * will fail after taking the palace down. The agent on the host checks the pattern a
 * second time; that is not distrust of this code, it is the observation that one layer
 * of validation is zero layers on the day that layer has a bug.
 *
 * **Refusing is as important as accepting**, and for a reason that is easy to miss:
 * an order nobody takes does not merely do nothing. It stays `pending`, the partial
 * unique index in the database counts it as in flight, and every later order for the
 * same dependency is refused. So writing an order while no agent is reporting would
 * not be optimism, it would disable the feature until somebody edited the table by
 * hand — which is why a silent agent is a refusal and not a warning.
 *
 * Reads close abandoned orders on the way past (self::closeAbandoned()). Lazily,
 * because the only moment the answer matters is when somebody looks, and a scheduled
 * job for it would be one more thing that can be missing on a machine where the agent
 * is already missing.
 */
final readonly class UpdateRequestService
{
    /**
     * The only shape a target version may have.
     *
     * Deliberately stricter than Version::parse(), which accepts two to four
     * components because that is what package indexes publish. Here the value is
     * going into a command line on the host, and the agent's own pattern is exactly
     * this one — a version this accepts and the agent rejects would be an order that
     * can be written and never executed, blocking the dependency until it timed out.
     */
    private const HOST_SAFE_TARGET = '/^[0-9]+\.[0-9]+\.[0-9]+$/';

    /**
     * What an order's log says when the agent never came back.
     *
     * Written into the row rather than left blank, because a `failed` order with an
     * empty log tells an administrator that something went wrong and nothing about
     * what — and this particular failure is not in the agent's journal either, since
     * the agent that would have written it is gone.
     */
    private const ABANDONED_LOG = 'Agent aktualizacji nie zgłosił wyniku w ciągu 45 minut od podjęcia zlecenia. '
        . 'Zlecenie domknięte jako nieudane, żeby nie blokowało następnych. '
        . 'Stan systemu jest nieznany — sprawdź na hoście: journalctl -u ws-memory-aktualizator.service';

    public function __construct(
        private DependencyCheckService $dependencies,
        private UpdateRequestRepository $requests,
        private UpdaterHeartbeat $heartbeat,
        private DependencyStateStore $store,
        private ReleaseCatalog $releases,
        private AuthorDirectory $authors,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Records an order to move one dependency to one version.
     *
     * @throws UpdateRefused when the order must not be written; the reason on it says
     *                       which of the five cases it is
     */
    public function request(User $actor, string $name, Version $to): UpdateRequest
    {
        $package = $this->dependencies->catalogPackageFor($name);
        if (null === $package) {
            throw UpdateRefused::unknownDependency($name);
        }

        if (1 !== preg_match(self::HOST_SAFE_TARGET, (string) $to)) {
            throw UpdateRefused::malformedTarget((string) $to);
        }

        // Before the in-flight check, never after: a `running` row left by an agent
        // that died an hour ago is not work in progress, and treating it as one would
        // answer 409 to every order from now until somebody noticed.
        $this->closeAbandoned();

        $record = $this->store->find($name);
        $this->assertReleaseExists($package, $to, $record?->latest);

        $inFlight = $this->requests->latestFor($name);
        if (null !== $inFlight && $inFlight->status->isInFlight()) {
            // Answered before the agent's pulse is considered, because it is the more
            // precise answer: if an update is already under way, whether the agent is
            // late is not what the reader needs to hear.
            throw UpdateRefused::alreadyInFlight($name);
        }

        if (!$this->updaterState()->healthy) {
            throw UpdateRefused::updaterSilent(UpdaterState::FRESH_FOR_SECONDS);
        }

        $request = new UpdateRequest(
            Uuid::v7()->toRfc4122(),
            $name,
            // What we believe is running, which may be nothing if the palace was
            // unreachable at the last check. The agent rolls back to the version in
            // `.env` rather than to this one, and warns when the two differ.
            $record?->installed,
            $to,
            UpdateStatus::Pending,
            $actor->getId()->toRfc4122(),
            new \DateTimeImmutable(),
        );

        // May still refuse: the check above loses to a second click in the same
        // millisecond, and the partial unique index is what that click breaks against.
        $this->requests->add($request);

        // Updating a dependency is an infrastructure change, so it leaves a trace with
        // the identity of whoever ordered it — same rule as granting a role (D-016).
        // Recorded after the write, so the trail never claims an order that was
        // refused; the order itself is the record that it was accepted.
        $this->audit->record(
            action: 'dependency.update.requested',
            actor: Actor::human($actor->getId()->toRfc4122(), $actor->isGlobalAdmin()),
            target: [
                'name' => $name,
                'requestId' => $request->id,
                'fromVersion' => null === $request->fromVersion ? null : (string) $request->fromVersion,
                'toVersion' => (string) $to,
            ],
        );

        return $request;
    }

    /**
     * The version situation plus the orders placed about it.
     *
     * @param DependencyStatus $status what the check service already worked out — passed
     *                                 in rather than fetched, so that opening the panel
     *                                 does not perform a second check
     */
    public function viewOf(DependencyStatus $status): DependencyView
    {
        $this->closeAbandoned();

        // One more than the history limit: the newest order is the headline, the rest
        // are the history, and fetching them in one query keeps the two consistent —
        // two queries could see a new order arrive between them and show it twice.
        $recent = $this->requests->recentFor($status->name(), DependencyView::HISTORY_LIMIT + 1);

        $latest = $recent[0] ?? null;
        $history = \array_slice($recent, 1);

        return new DependencyView($status, $latest, $history, $this->namesIn($recent));
    }

    public function updaterState(): UpdaterState
    {
        return UpdaterState::from($this->heartbeat->lastSeen(), new \DateTimeImmutable());
    }

    /**
     * Refuses a target the release catalogue does not vouch for.
     *
     * The stored answer from the last successful check is consulted first, and not as
     * a cache: it is the number the panel offered, so in the normal case the
     * confirmation costs nothing and works with PyPI down. That matters because the
     * alternative — going to the network on every order — would make an update
     * impossible exactly when PyPI is unreachable, which is a state this system is
     * required to survive rather than merely report (TODO-015).
     *
     * @param Version|null $known the newest release the last successful check recorded
     *
     * @throws UpdateRefused
     */
    private function assertReleaseExists(string $package, Version $to, ?Version $known): void
    {
        if (null !== $known && $known->equals($to)) {
            return;
        }

        try {
            $latest = $this->releases->latestStable($package);
        } catch (CheckFailed $e) {
            // We do not know whether the release exists, so we do not write the order.
            // The message says the catalogue could not be reached rather than that the
            // version is wrong — the reader's next step is different in each case.
            throw UpdateRefused::targetUnconfirmed($to, $e->getMessage());
        }

        if (null === $latest || !$latest->equals($to)) {
            throw UpdateRefused::targetNotInCatalog($package, $to);
        }
    }

    private function closeAbandoned(): void
    {
        $now = new \DateTimeImmutable();

        $this->requests->failAbandoned(
            $now->modify(\sprintf('-%d seconds', UpdateRequest::ABANDONED_AFTER_SECONDS)),
            self::ABANDONED_LOG,
            $now,
        );
    }

    /**
     * Display names for the people behind these orders, in one query.
     *
     * @param list<UpdateRequest> $requests
     *
     * @return array<string, string>
     */
    private function namesIn(array $requests): array
    {
        $ids = [];
        foreach ($requests as $request) {
            $ids[$request->requestedBy] = $request->requestedBy;
        }

        return $this->authors->namesOf(array_values($ids));
    }
}
