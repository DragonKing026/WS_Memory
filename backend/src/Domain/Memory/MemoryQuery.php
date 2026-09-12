<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * What to look for in memory — everything except where to look.
 *
 * The omission is deliberate. A query object that could also carry a wing would
 * let a caller hand one in from a request payload, and the whole permission
 * model would rest on nobody ever doing that. Here the "where" is a separate,
 * mandatory argument of MemoryStore::search(), computed from the actor's roles
 * and from nothing else.
 */
final readonly class MemoryQuery
{
    /** The palace refuses longer queries; failing here gives a better message than an HTTP 422 from a sidecar. */
    public const MAX_QUERY_LENGTH = 250;

    public const DEFAULT_LIMIT = 5;
    public const MAX_LIMIT = 100;

    public function __construct(
        public string $text,
        public int $limit = self::DEFAULT_LIMIT,
        public ?MemoryKind $kind = null,
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $before = null,
        public ?float $maxDistance = null,
    ) {
        if ('' === trim($text)) {
            throw new \InvalidArgumentException('A search query cannot be empty.');
        }

        if (mb_strlen($text) > self::MAX_QUERY_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A search query may be at most %d characters; background belongs in the content, not in the query.',
                self::MAX_QUERY_LENGTH,
            ));
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('The result limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        if (null !== $since && null !== $before && $since > $before) {
            throw new \InvalidArgumentException('The start of the time window is after its end.');
        }
    }

    /**
     * The room to restrict the search to, or null for every room.
     *
     * Graph facts are not drawers, so asking for them here would filter
     * everything out rather than return facts; the service routes that kind to
     * the graph instead.
     */
    public function room(): ?string
    {
        return $this->kind?->isDrawer() ? $this->kind->room() : null;
    }
}
