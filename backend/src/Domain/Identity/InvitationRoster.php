<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Port: outstanding and past invitations, read for administration.
 *
 * Paged for the same reason accounts are: an installation that has run for a year
 * has a row here for every person who ever failed to accept in time, and nobody
 * deletes those.
 */
interface InvitationRoster
{
    /**
     * One page of invitations, newest first.
     *
     * @return list<InvitationSummary>
     */
    public function page(int $limit, int $offset): array;

    public function one(string $invitationId): ?InvitationSummary;
}
