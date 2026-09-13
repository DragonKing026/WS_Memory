<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Port: what the newest release of a package is.
 *
 * A port rather than a call to PyPI inline, for one reason that matters more than
 * tidiness: the interesting behaviour of this thing is what it does when the
 * network is gone, and that is only testable behind a seam.
 *
 * The contract distinguishes three outcomes, and the distinction is the whole
 * point (see CheckFailed):
 *
 *   - a Version — this is the newest stable release, offer it;
 *   - `null` — we reached the catalogue and it knows of no usable release, so
 *     there is genuinely nothing to update to;
 *   - CheckFailed — we do not know, and must not answer the question.
 *
 * An implementation that swallowed a timeout into `null` would satisfy the
 * signature and report "brak nowszej wersji" to an administrator whose PyPI is
 * simply unreachable. That is the failure this interface is shaped to prevent.
 */
interface ReleaseCatalog
{
    /**
     * The newest stable, non-withdrawn release of $package.
     *
     * Implementations must skip pre-releases and releases the author has yanked:
     * proposing an upgrade to a release its own author withdrew is worse than
     * proposing none.
     *
     * @return Version|null null only when the catalogue answered and holds no
     *                      usable release — never as a stand-in for a failure
     *
     * @throws CheckFailed when the catalogue could not be consulted or understood
     */
    public function latestStable(string $package): ?Version;
}
