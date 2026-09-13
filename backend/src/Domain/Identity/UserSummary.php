<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * One row of the account list an administrator sees.
 *
 * Not the `User` entity. The screen needs two numbers that are not on the account
 * at all — how many spaces it reaches and how many agents write on its behalf —
 * and the entity has no business growing a pair of collections just so a listing
 * can count them. Reading them as part of the same row is also the only way to
 * keep the listing to one query (see DoctrineUserRoster).
 *
 * Carries no password hash and no token hash. A listing that never holds a secret
 * cannot leak one by having a field added to its serialisation.
 */
final readonly class UserSummary
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public bool $isGlobalAdmin,
        public bool $isActive,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastLoginAt,
        /**
         * Every membership, the account's own private space included.
         *
         * Counting only shared spaces was the alternative and it reads worse: the
         * private one exists from the first moment for everybody (inviolable rule
         * 6), so a column of zeros would invite the reader to conclude that a
         * fresh account cannot write anywhere.
         */
        public int $spaceCount,
        /**
         * Agent tokens that still work — revoked ones are not counted.
         *
         * The question this answers on the screen is "does anything automated
         * write as this person right now", which a historical total would answer
         * wrongly, and most wrongly exactly after somebody deactivated the account
         * to stop precisely that.
         */
        public int $tokenCount,
    ) {
    }
}
