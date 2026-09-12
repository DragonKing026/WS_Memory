<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Port: turning a presented secret into an identity, and recording that it was used.
 *
 * Declared in the domain because both halves are rules rather than plumbing. A
 * revoked or expired token must resolve to nothing **at the next call** — not at
 * the end of a cache lifetime — and every call must leave a trace, because a
 * credential nobody can tell is dead cannot be retired safely.
 */
interface AgentTokenDirectory
{
    /**
     * The identity behind a plain token, or null if there is none to be had.
     *
     * Null covers every reason at once: no such token, revoked, expired, owner
     * deactivated. The caller answers 401 without saying which, because the
     * difference is useful only to somebody guessing tokens.
     */
    public function resolve(#[\SensitiveParameter] string $plainToken): ?AgentIdentity;

    /**
     * Records the use and answers how many calls this token has made in the
     * current rate-limit window.
     *
     * One write for both, because they are the same write (D-022): the row has
     * to be touched anyway to record `last_used_at`, so counting is free. The
     * returned number is what the caller compares against the limit.
     */
    public function noteUsage(string $tokenId, ?string $ip): int;

    /**
     * Who owns a token, whether or not it is still usable.
     *
     * Needed when reconstructing authorship after the fact: a revision written by an
     * agent records the token and, because exactly one author column may be set, not
     * the person behind it. Publishing that revision has to name a person, so the
     * owner is looked up here — and a revoked or expired token must still answer,
     * since who wrote something does not change when their credential is retired.
     */
    public function ownerOf(string $tokenId): ?string;
}
