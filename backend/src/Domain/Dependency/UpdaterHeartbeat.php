<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Port: the agent's pulse.
 *
 * The backend cannot see whether a script is installed on the host — that is the
 * entire point of D-032, no container may look. So the only evidence that an agent
 * exists is that it says so, once per run, whether or not there was work to do.
 *
 * Which makes this a load-bearing part of the feature rather than monitoring
 * decoration: without a pulse the panel must say "aktualizator niedostępny" instead
 * of offering a button, and an order must be refused rather than left pending for an
 * agent that will never take it.
 */
interface UpdaterHeartbeat
{
    public function record(\DateTimeImmutable $seenAt): void;

    /**
     * @return \DateTimeImmutable|null null when no agent has ever reported — which
     *                                means none is installed, not that one is late
     */
    public function lastSeen(): ?\DateTimeImmutable;
}
