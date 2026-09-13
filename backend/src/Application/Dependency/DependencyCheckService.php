<?php

declare(strict_types=1);

namespace App\Application\Dependency;

use App\Domain\Dependency\CheckFailed;
use App\Domain\Dependency\DependencyRecord;
use App\Domain\Dependency\DependencyStateStore;
use App\Domain\Dependency\DependencyStatus;
use App\Domain\Dependency\ReleaseCatalog;
use App\Domain\Dependency\RunningVersion;
use App\Domain\Dependency\Version;
use Psr\Log\LoggerInterface;

/**
 * Finds out where MemPalace stands, and remembers the answer.
 *
 * The reason this exists is the one in TODO-015: the palace is pinned on purpose,
 * which is right, and the side effect is that nobody learns a new release came
 * out. Two minor versions went by unnoticed, and the only reason anybody found
 * out was that somebody asked by hand.
 *
 * **The one rule worth reading before changing anything here.** A failed check
 * never erases a successful one. When PyPI cannot be reached, `latest_version` and
 * `checked_at` stay exactly as the last good check left them and `check_problem`
 * is filled in — so the panel says "3.9.0, dowiedzieliśmy się we wtorek, od wtorku
 * nie udało się sprawdzić". Overwriting `latest` with null on failure would turn an
 * outage into "brak nowszej wersji", which is the one sentence this feature exists
 * to stop being wrong.
 *
 * The two sources are queried independently and their failures are collected
 * rather than short-circuited: the palace being down should not cost us the
 * knowledge that 3.9.0 exists, and PyPI being down should not hide which version
 * is running. Both are needed for `updateAvailable`, neither is needed for the
 * other to be useful.
 *
 * The pinned version is read from configuration, because that is the only place it
 * exists — it says what the image was MEANT to be built from. It is kept next to
 * the running number rather than instead of it: when they differ, somebody edited
 * `.env` and did not rebuild, and that is worth seeing.
 */
final readonly class DependencyCheckService
{
    /** The one dependency this tracks. A constant, because the API routes it by name. */
    public const MEMPALACE = 'mempalace';

    /** The name of the package on PyPI — the same string here, and not guaranteed to stay so. */
    private const MEMPALACE_PACKAGE = 'mempalace';

    private const MEMPALACE_LABEL = 'MemPalace';

    public function __construct(
        private ReleaseCatalog $releases,
        private RunningVersion $running,
        private DependencyStateStore $store,
        private LoggerInterface $logger,
        private ?string $pinnedVersion = null,
    ) {
    }

    /**
     * Whether this is a dependency we track at all.
     *
     * Asked by the API so an unknown name is a 404 rather than a row invented on
     * the spot. A check that silently accepts any name would fill the table with
     * typos and report each of them as never checked.
     */
    public function knows(string $name): bool
    {
        return self::MEMPALACE === $name;
    }

    /**
     * What this dependency is called in the release catalogue, or null if we do not
     * track it.
     *
     * Exposed because ordering an update has to confirm the target version exists on
     * PyPI, and the package name is not the same fact as the dependency name we route
     * by — they happen to coincide today and nothing guarantees they will. Kept here
     * rather than duplicated in UpdateRequestService so that the check and the order
     * can never ask PyPI about two different packages.
     */
    public function catalogPackageFor(string $name): ?string
    {
        return self::MEMPALACE === $name ? self::MEMPALACE_PACKAGE : null;
    }

    /**
     * What we last learned, without asking anybody.
     *
     * The panel is opened far more often than every six hours, and a page load
     * that reaches out to PyPI would make the panel as slow and as fragile as the
     * network. When nothing has been stored yet there is nothing to show, so this
     * falls through to a real check — otherwise a freshly deployed system would
     * display empty fields until the scheduler's first run, six hours later.
     */
    public function status(): DependencyStatus
    {
        $stored = $this->store->find(self::MEMPALACE);

        if (null === $stored) {
            return $this->check();
        }

        // The pinned number comes from configuration on every read rather than
        // from the stored row: it can change with a redeploy without any check
        // having run since, and the stale copy would be the one thing in the
        // panel we could have known was wrong.
        return new DependencyStatus(
            self::MEMPALACE_LABEL,
            new DependencyRecord(
                $stored->name,
                $stored->installed,
                $this->pinned(),
                $stored->latest,
                $stored->checkedAt,
                $stored->checkProblem,
            ),
        );
    }

    /**
     * Asks both sources now, stores the outcome, and reports it.
     */
    public function check(): DependencyStatus
    {
        $previous = $this->store->find(self::MEMPALACE) ?? new DependencyRecord(self::MEMPALACE);

        $problems = [];

        $installed = $previous->installed;
        try {
            $installed = $this->running->current();
        } catch (CheckFailed $e) {
            // The previous number is kept rather than blanked: "3.7.0, a teraz
            // pałac nie odpowiada" is more useful than an empty field, and the
            // problem line says not to trust it as current.
            $problems[] = $e->getMessage();
            $this->logger->warning('Nie udało się ustalić działającej wersji pałacu.', ['reason' => $e->getMessage()]);
        }

        $latest = $previous->latest;
        $checkedAt = $previous->checkedAt;
        try {
            $fetched = $this->releases->latestStable(self::MEMPALACE_PACKAGE);

            if (null === $fetched) {
                // The catalogue answered and holds nothing installable. Strange
                // for a package we run, so it is a problem rather than a silent
                // null — but the last number we knew still stands.
                $problems[] = \sprintf(
                    'PyPI nie podaje żadnego stabilnego wydania pakietu %s.',
                    self::MEMPALACE_PACKAGE,
                );
            } else {
                $latest = $fetched;
                $checkedAt = new \DateTimeImmutable();
            }
        } catch (CheckFailed $e) {
            $problems[] = $e->getMessage();
            $this->logger->warning('Nie udało się sprawdzić najnowszej wersji pałacu.', [
                'reason' => $e->getMessage(),
            ]);
        }

        $record = new DependencyRecord(
            self::MEMPALACE,
            $installed,
            $this->pinned(),
            $latest,
            $checkedAt,
            [] === $problems ? null : implode(' ', $problems),
        );

        $this->store->save($record);

        return new DependencyStatus(self::MEMPALACE_LABEL, $record);
    }

    /**
     * @return list<DependencyStatus>
     */
    public function all(): array
    {
        return [$this->status()];
    }

    /**
     * The configured version, or nothing if it is unreadable.
     *
     * A malformed `MEMPALACE_VERSION` must not stop the check: the number it would
     * have produced is the least important of the three, and refusing to report
     * the other two over it would be a poor trade.
     */
    private function pinned(): ?Version
    {
        $configured = null === $this->pinnedVersion ? '' : trim($this->pinnedVersion);

        return '' === $configured ? null : Version::tryParse($configured);
    }
}
