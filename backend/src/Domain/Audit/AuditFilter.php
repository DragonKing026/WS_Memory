<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * What slice of the audit log a screen is asking for.
 *
 * One object rather than seven parameters, because the same slice has to be described
 * twice for every request — once to fetch the page and once to count what the page is a
 * slice of — and two call sites drifting apart would show a count that does not match
 * the rows under it.
 *
 * Normalising happens here, in the constructor, and not in the controller: the limit is
 * clamped, blank strings become null. Both surfaces then get the same behaviour, and the
 * adapter downstream can treat null as "no filter" without re-checking for empty
 * strings — a `WHERE action = ''` matching nothing is the kind of bug that reads as an
 * empty audit log.
 */
final readonly class AuditFilter
{
    /** How many entries one page holds when the caller does not say. */
    public const PAGE_SIZE = 100;

    /**
     * The most a caller may ask for at once.
     *
     * The log is the one table in this system that grows without bound and this is the
     * one screen that reads all of it, so the ceiling is the difference between a slow
     * page and a request that carries half a million rows of JSON out of the database.
     */
    public const MAX_PAGE_SIZE = 500;

    public ?string $action;

    public ?string $spaceSlug;

    /**
     * A user id or an agent token id — whichever the panel had.
     *
     * Matched against both columns, because the two are not interchangeable and the
     * screen showing the names does not need to know which kind it is holding.
     */
    public ?string $actor;

    public int $limit;

    public int $offset;

    public function __construct(
        ?string $action = null,
        ?string $spaceSlug = null,
        ?string $actor = null,
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $before = null,
        int $limit = self::PAGE_SIZE,
        int $offset = 0,
    ) {
        $this->action = self::cleaned($action);
        $this->spaceSlug = self::cleaned($spaceSlug);
        $this->actor = self::cleaned($actor);
        $this->limit = max(1, min($limit, self::MAX_PAGE_SIZE));
        $this->offset = max(0, $offset);
    }

    private static function cleaned(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return '' === $trimmed ? null : $trimmed;
    }
}
