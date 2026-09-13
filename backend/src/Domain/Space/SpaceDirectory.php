<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * Port: every space in the installation, with its size, for the administration screen.
 *
 * Deliberately separate from SpaceCatalog, which answers "what is this one space" for
 * the permission and palace paths and is called on ordinary requests. This port exists
 * for one screen that only a global administrator reaches, and its questions are the
 * opposite shape: all of them at once, with counters, ignoring who may read what.
 *
 * The counters are part of the port rather than something the caller works out,
 * because the only way to get them right is to let the database aggregate them in the
 * same statement that fetches the page. A caller asking each space how many members it
 * has produces one query per row — the shape that stays invisible until the
 * installation has three hundred spaces and the panel takes four seconds to open.
 */
interface SpaceDirectory
{
    /** How many spaces one page holds when the caller does not say. */
    public const PAGE_SIZE = 50;

    /** The most a caller may ask for in one page. */
    public const MAX_PAGE_SIZE = 200;

    /**
     * One page of spaces, private ones included.
     *
     * @return list<SpaceOverview>
     */
    public function overview(int $limit = self::PAGE_SIZE, int $offset = 0): array;

    /**
     * How many spaces exist in total, so a paging screen knows what it is paging
     * through. Counted, not estimated: the number is printed next to the list and an
     * approximation there reads as a bug.
     */
    public function total(): int;

    /**
     * Everyone who holds a role in this space, with the granter's name resolved.
     *
     * An empty list is a real answer — a space can lose its last member — so a caller
     * that needs to tell "no members" from "no such space" checks the space itself
     * first rather than reading it out of this list's emptiness.
     *
     * @return list<SpaceMemberView>
     */
    public function membersOf(SpaceId $space): array;
}
