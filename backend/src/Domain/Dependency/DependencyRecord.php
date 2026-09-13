<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * The last thing we learned about one dependency — exactly one row of
 * `ws.dependency_state`.
 *
 * Separate from DependencyStatus, which is what the API answers, because the two
 * carry different things for different reasons. This one is storage: no label, no
 * derived flag, nothing a future reader could mistake for a decision. The
 * decisions live next door, computed from these fields every time rather than
 * written down beside them — a stored `update_available` column would be a second
 * source of truth that goes stale silently the moment a check writes one field
 * and not the other.
 *
 * `$checkedAt` is the time of the last check that SUCCEEDED, not of the last
 * attempt. The difference is what makes "nie udało się sprawdzić od wtorku"
 * expressible: a failed attempt fills `$checkProblem` and leaves this timestamp
 * — and `$latest` with it — where the last good answer put them.
 */
final readonly class DependencyRecord
{
    public function __construct(
        public string $name,
        public ?Version $installed = null,
        public ?Version $pinned = null,
        public ?Version $latest = null,
        public ?\DateTimeImmutable $checkedAt = null,
        public ?string $checkProblem = null,
    ) {
    }

    /**
     * Whether the newest known release is actually newer than what is running.
     *
     * Numeric comparison through Version, never a string comparison: `'3.10.0'`
     * sorts below `'3.9.0'` as text, and the resulting answer — no update
     * available — is both wrong and completely silent.
     *
     * False when either side is unknown. Offering an update to a version we
     * cannot compare against would be a guess dressed as a recommendation.
     */
    public function updateAvailable(): bool
    {
        return null !== $this->installed
            && null !== $this->latest
            && $this->latest->isNewerThan($this->installed);
    }

    /**
     * A record identical to this one but carrying the reason a check failed.
     *
     * The point of this method is what it does NOT touch: `$latest` and
     * `$checkedAt` survive. An administrator whose PyPI went down keeps seeing
     * the last release we knew about, with the date we learned it, plus a problem
     * saying why the number is old — rather than an empty field that reads as
     * "you are up to date".
     */
    public function withProblem(string $problem, ?Version $installed = null, ?Version $pinned = null): self
    {
        return new self(
            $this->name,
            $installed ?? $this->installed,
            $pinned ?? $this->pinned,
            $this->latest,
            $this->checkedAt,
            $problem,
        );
    }
}
