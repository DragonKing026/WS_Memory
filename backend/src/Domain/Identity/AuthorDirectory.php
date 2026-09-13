<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Port: turning the identifiers stored on a revision into something a person can read.
 *
 * A revision records a raw user id or a raw agent-token id and nothing else — no
 * relation to the user, no copied name. That is deliberate: a name copied at write
 * time goes stale, and a foreign key from history to accounts would stop an account
 * from ever being deleted. But it means a revision list straight from the database
 * reads as a column of UUIDs, which answers "who wrote this" with "no idea".
 *
 * Resolved here, in bulk, at read time. In bulk because a history screen shows twenty
 * revisions, and twenty lookups is how a list becomes slow without anybody being able
 * to point at the slow part.
 */
interface AuthorDirectory
{
    /**
     * Display names for these people.
     *
     * Identifiers absent from the map belong to accounts that no longer exist. The
     * caller shows those as unknown rather than hiding the revision — history that
     * quietly loses entries when somebody leaves is worse than history naming nobody.
     *
     * @param list<string> $userIds
     *
     * @return array<string, string> keyed by user id
     */
    public function namesOf(array $userIds): array;

    /**
     * Labels for these agent tokens, each with its owner's name where there is one.
     *
     * A token's label is what a person recognises it by ("agent CI", "Claude na
     * laptopie"), and the owner matters because an agent acts for somebody.
     *
     * @param list<string> $tokenIds
     *
     * @return array<string, string> keyed by token id
     */
    public function tokenLabelsOf(array $tokenIds): array;
}
