<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Port: the list of accounts, read for administration.
 *
 * Declared here rather than reached for through the ORM in the controller because
 * of the third method. `countActiveGlobalAdmins()` is not a listing convenience —
 * it is the number an installation must never let reach zero, and a rule that
 * important should be visible in the domain rather than buried in a repository
 * call inside a use case.
 */
interface UserRoster
{
    /**
     * One page of accounts, newest first.
     *
     * @param string|null $query matches e-mail address or display name; null lists everybody
     *
     * @return list<UserSummary>
     */
    public function page(?string $query, int $limit, int $offset): array;

    /**
     * One account in the same shape the listing uses, or null if there is no such id.
     *
     * Exists so that the answer to "I changed this account" is the same row the
     * screen already knows how to draw. A second shape for a single account would
     * drift from the listing's the first time a field was added to either.
     */
    public function one(string $userId): ?UserSummary;

    /**
     * How many accounts can still administer this installation.
     *
     * Deactivated administrators are not counted. They cannot sign in, so they
     * cannot grant a role or issue an invitation — which is the whole reason this
     * number is guarded.
     */
    public function countActiveGlobalAdmins(): int;
}
