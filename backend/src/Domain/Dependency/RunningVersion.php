<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Port: which version is actually answering right now.
 *
 * Deliberately not "which version did we pin". The pinned number says what was
 * meant to be built; this one says what is running, and the two drift apart the
 * moment somebody edits `.env` without rebuilding the image. That gap is
 * information in its own right, so the system has to be able to see both — which
 * it cannot if the only source is configuration.
 *
 * Like ReleaseCatalog, `null` means "the service answered and told us nothing
 * useful", and a service that does not answer is CheckFailed. A running version
 * reported as absent because of a timeout would put an empty field in the panel
 * where an administrator expects a number, and nothing would say why.
 */
interface RunningVersion
{
    /**
     * @return Version|null null only when the service answered without naming a
     *                      version it can be held to
     *
     * @throws CheckFailed when the service did not answer at all
     */
    public function current(): ?Version;
}
