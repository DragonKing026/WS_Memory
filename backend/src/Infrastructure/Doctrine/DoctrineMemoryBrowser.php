<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\MemoryBrowser;
use App\Domain\Memory\MemoryEntryView;
use App\Domain\Memory\MemoryKind;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Adapter: the registry read as a listing.
 *
 * Plain DBAL rather than the ORM, for the same reason as the rest of this namespace:
 * a listing hydrating entities would pull document objects along for the ride to show
 * a title and a date.
 *
 * The ordering is `(space_id, created_at)` — the index laid down in
 * Version20260912000003 — read backwards. A btree scans in reverse just as cheaply,
 * which is why no descending copy of that index exists.
 */
final readonly class DoctrineMemoryBrowser implements MemoryBrowser
{
    public function __construct(private Connection $connection)
    {
    }

    public function recent(
        array $spaces,
        ?MemoryKind $kind = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $before = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        // The port types this as non-empty, but a PHPDoc type is not enforced at
        // runtime, and what it guards is the difference between "these spaces" and
        // "everybody's content" (inviolable rule 3).
        // @phpstan-ignore identical.alwaysFalse
        if ([] === $spaces) {
            throw new \InvalidArgumentException('Przeglądanie pamięci wymaga wskazania przestrzeni.');
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    e.drawer_id,
                    e.kind,
                    e.title,
                    e.tags,
                    e.created_at,
                    (e.author_agent_token_id IS NOT NULL) AS by_ai,
                    s.slug                                AS space_slug,
                    d.slug                                AS document_slug,
                    (d.verified_at IS NOT NULL)           AS verified
                FROM ws.memory_entries e
                JOIN ws.spaces s ON s.id = e.space_id
                LEFT JOIN ws.documents d ON d.id = e.document_id
                WHERE s.slug IN (:slugs)
                  AND (:kind::text IS NULL OR e.kind = :kind)
                  AND (:since::timestamp IS NULL OR e.created_at >= :since)
                  AND (:before::timestamp IS NULL OR e.created_at < :before)
                ORDER BY e.created_at DESC, e.drawer_id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            [
                'slugs' => array_map(static fn (SpaceId $s): string => $s->value, $spaces),
                'kind' => $kind?->value,
                'since' => $since?->format('Y-m-d H:i:s'),
                'before' => $before?->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'offset' => $offset,
            ],
            ['slugs' => ArrayParameterType::STRING],
        );

        $entries = [];
        foreach ($rows as $row) {
            $entry = self::viewFrom($row);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function viewFrom(array $row): ?MemoryEntryView
    {
        $kind = MemoryKind::tryFrom((string) $row['kind']);
        if (null === $kind) {
            // A kind written by a newer version of the application. Skipped rather than
            // guessed: a row labelled as the wrong class of knowledge misleads a reader
            // who is browsing precisely to see what class things are.
            return null;
        }

        try {
            $filedAt = new \DateTimeImmutable((string) $row['created_at']);
        } catch (\Exception) {
            return null;
        }

        return new MemoryEntryView(
            drawer: new DrawerId((string) $row['drawer_id']),
            space: new SpaceId((string) $row['space_slug']),
            kind: $kind,
            title: (string) $row['title'],
            tags: self::tagsFrom($row['tags'] ?? null),
            byAi: (bool) $row['by_ai'],
            // Only a document can be verified; for anything else the LEFT JOIN gives
            // NULL, which casts to false — the honest answer.
            verified: (bool) $row['verified'],
            documentSlug: \is_string($row['document_slug'] ?? null) && '' !== $row['document_slug']
                ? $row['document_slug']
                : null,
            filedAt: $filedAt,
        );
    }

    /** @return list<string> */
    private static function tagsFrom(mixed $raw): array
    {
        if (!\is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $tag) {
            if (\is_string($tag) && '' !== $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
