<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\EntryFacts;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Memory\SourceBinding;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter: the registry on plain DBAL.
 *
 * Deliberately not through the ORM, for the same reason as
 * DoctrineSpaceMembershipRepository: these queries sit on the permission path of
 * every read, and hydrating entities to compare two columns would put the
 * identity map between a revoked membership and its effect.
 *
 * The space is resolved by slug inside the INSERT rather than fetched first. A
 * separate SELECT would open a window in which the space disappears between the
 * check and the write, and the row would then name a space that no longer
 * exists — the foreign key would catch it, but with an error nobody could read.
 */
final readonly class DoctrineMemoryRegistry implements MemoryRegistry
{
    public function __construct(private Connection $connection)
    {
    }

    public function register(MemoryWrite $write): void
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.memory_entries (
                    id, drawer_id, space_id, kind, author_user_id, author_agent_token_id,
                    document_id, title, tags, content_hash, source_replica, source_drawer_id,
                    publish_batch_id, created_at
                )
                SELECT
                    :id, :drawerId, s.id, :kind, :authorUserId, :authorTokenId,
                    :documentId, :title, CAST(:tags AS JSONB), :contentHash, :sourceReplica, :sourceDrawerId,
                    :publishBatchId, :createdAt
                FROM ws.spaces s
                WHERE s.slug = :spaceSlug
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'drawerId' => $write->drawer->value,
                'spaceSlug' => $write->space->value,
                'kind' => $write->kind->value,
                'authorUserId' => $write->author->userId,
                'authorTokenId' => $write->author->agentTokenId,
                'documentId' => $write->documentId,
                'title' => $write->title,
                'tags' => json_encode($write->tags, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                'contentHash' => $write->contentHash,
                'sourceReplica' => $write->sourceReplica,
                'sourceDrawerId' => $write->sourceDrawerId,
                'publishBatchId' => $write->publishBatchId,
                // The time the content was filed, which for anything written through
                // our own surfaces is now. A publication says otherwise, and it is
                // right to: a laptop back from a week offline sends a week of drawers
                // at once, and dating them all today would make the browse screen —
                // ordered by this very column — claim a week of work happened in one
                // minute.
                'createdAt' => ($write->filedAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        if (0 === $affected) {
            // The SELECT found no space, so nothing was written. Left as an
            // exception rather than a silent no-op: the caller has just filed
            // content into the palace and is about to be told it succeeded.
            throw new \DomainException(\sprintf(
                'Nie ma przestrzeni „%s" — zapis nie został zaksięgowany.',
                $write->space->value,
            ));
        }
    }

    public function describe(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->value] = $id->value;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    e.drawer_id,
                    e.kind,
                    e.title,
                    e.created_at,
                    -- One author column is always set and only one (the schema
                    -- enforces it), so the token column alone answers "AI or not".
                    (e.author_agent_token_id IS NOT NULL) AS by_ai,
                    d.slug                                AS document_slug,
                    (d.verified_at IS NOT NULL)           AS verified
                FROM ws.memory_entries e
                LEFT JOIN ws.documents d ON d.id = e.document_id
                WHERE e.drawer_id IN (:ids)
                SQL,
            ['ids' => array_values($unique)],
            ['ids' => ArrayParameterType::STRING],
        );

        $facts = [];
        foreach ($rows as $row) {
            $kind = MemoryKind::tryFrom((string) $row['kind']);
            if (null === $kind) {
                // A kind written by a newer version of the application. Skipped
                // rather than guessed: a result labelled as the wrong class of
                // knowledge is worse than one the caller treats as undescribed.
                continue;
            }

            $filedAt = null;
            if (\is_string($row['created_at']) && '' !== $row['created_at']) {
                try {
                    $filedAt = new \DateTimeImmutable($row['created_at']);
                } catch (\Exception) {
                    $filedAt = null;
                }
            }

            $facts[(string) $row['drawer_id']] = new EntryFacts(
                kind: $kind,
                title: (string) $row['title'],
                byAi: (bool) $row['by_ai'],
                // Only documents can be verified; for anything else the LEFT JOIN
                // gives NULL, which casts to false — the honest answer.
                verified: (bool) $row['verified'],
                documentSlug: \is_string($row['document_slug']) && '' !== $row['document_slug']
                    ? $row['document_slug']
                    : null,
                filedAt: $filedAt,
            );
        }

        return $facts;
    }

    public function spacesFor(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Deduplicated by key rather than by array_unique: the same drawer may
        // appear twice in one page of results, and asking the database about it
        // twice is pointless.
        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->value] = $id->value;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT e.drawer_id, s.slug
                FROM ws.memory_entries e
                JOIN ws.spaces s ON s.id = e.space_id
                WHERE e.drawer_id IN (:ids)
                SQL,
            ['ids' => array_values($unique)],
            ['ids' => ArrayParameterType::STRING],
        );

        $spaces = [];
        foreach ($rows as $row) {
            $spaces[(string) $row['drawer_id']] = new SpaceId((string) $row['slug']);
        }

        return $spaces;
    }

    public function spaceFor(DrawerId $id): ?SpaceId
    {
        $slug = $this->connection->fetchOne(
            <<<'SQL'
                SELECT s.slug
                FROM ws.memory_entries e
                JOIN ws.spaces s ON s.id = e.space_id
                WHERE e.drawer_id = :drawerId
                SQL,
            ['drawerId' => $id->value],
        );

        return \is_string($slug) ? new SpaceId($slug) : null;
    }

    public function drawerForDocument(string $documentId): ?DrawerId
    {
        $drawer = $this->connection->fetchOne(
            'SELECT drawer_id FROM ws.memory_entries WHERE document_id = :documentId',
            ['documentId' => $documentId],
        );

        return \is_string($drawer) ? new DrawerId($drawer) : null;
    }

    public function rebind(DrawerId $from, DrawerId $to): void
    {
        $affected = $this->connection->executeStatement(
            'UPDATE ws.memory_entries SET drawer_id = :to WHERE drawer_id = :from',
            ['from' => $from->value, 'to' => $to->value],
        );

        if (0 === $affected) {
            throw new \DomainException(\sprintf('Rejestr nie zna szuflady %s.', $from->value));
        }
    }

    public function bindingForSource(string $sourceReplica, string $sourceDrawerId): ?SourceBinding
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT e.drawer_id, s.slug
                FROM ws.memory_entries e
                JOIN ws.spaces s ON s.id = e.space_id
                WHERE e.source_replica = :replica AND e.source_drawer_id = :sourceDrawer
                SQL,
            ['replica' => $sourceReplica, 'sourceDrawer' => $sourceDrawerId],
        );

        if (false === $row) {
            return null;
        }

        return new SourceBinding(
            new DrawerId((string) $row['drawer_id']),
            new SpaceId((string) $row['slug']),
        );
    }

    public function drawerWithContent(SpaceId $space, string $contentHash): ?DrawerId
    {
        // Answered by idx_entries_dedup, which is why the space comes first in the
        // query as it does in the index. Deduplication is per target space by
        // design (D-014): three private spaces legitimately hold three copies, and
        // that is what makes the plugin offer a mapping instead.
        $drawer = $this->connection->fetchOne(
            <<<'SQL'
                SELECT e.drawer_id
                FROM ws.memory_entries e
                JOIN ws.spaces s ON s.id = e.space_id
                WHERE s.slug = :slug AND e.content_hash = :hash
                LIMIT 1
                SQL,
            ['slug' => $space->value, 'hash' => $contentHash],
        );

        return \is_string($drawer) ? new DrawerId($drawer) : null;
    }

    public function refresh(MemoryWrite $write): void
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.memory_entries
                SET space_id = (SELECT s.id FROM ws.spaces s WHERE s.slug = :spaceSlug),
                    title = :title,
                    tags = CAST(:tags AS JSONB),
                    content_hash = :contentHash,
                    source_replica = :sourceReplica,
                    source_drawer_id = :sourceDrawerId,
                    publish_batch_id = :publishBatchId,
                    created_at = :createdAt
                WHERE drawer_id = :drawerId
                  AND (SELECT s.id FROM ws.spaces s WHERE s.slug = :spaceSlug) IS NOT NULL
                SQL,
            [
                'drawerId' => $write->drawer->value,
                'spaceSlug' => $write->space->value,
                'title' => $write->title,
                'tags' => json_encode($write->tags, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                'contentHash' => $write->contentHash,
                'sourceReplica' => $write->sourceReplica,
                'sourceDrawerId' => $write->sourceDrawerId,
                'publishBatchId' => $write->publishBatchId,
                // The local filing time wins over now(). A laptop back from a week
                // offline sends a week of drawers at once, and dating them all today
                // would make the browse screen — ordered by this column — claim a
                // week of work happened in a minute.
                'createdAt' => ($write->filedAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        if (0 === $affected) {
            // Either the drawer is unknown or the space is. Both mean the caller is
            // about to be told a publication succeeded, and neither is survivable
            // silently — the content is in the palace by now.
            throw new \DomainException(\sprintf(
                'Rejestr nie zna szuflady %s w przestrzeni „%s" — odświeżenie wpisu się nie udało.',
                $write->drawer->value,
                $write->space->value,
            ));
        }
    }

    public function drawersInBatch(string $batchId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT drawer_id
                FROM ws.memory_entries
                WHERE publish_batch_id = :batch
                ORDER BY created_at, drawer_id
                SQL,
            ['batch' => $batchId],
        );

        return array_map(
            static fn (mixed $value): DrawerId => new DrawerId((string) $value),
            $rows,
        );
    }

    public function forget(array $drawers): int
    {
        if ([] === $drawers) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM ws.memory_entries WHERE drawer_id IN (:ids)',
            ['ids' => array_map(static fn (DrawerId $id): string => $id->value, $drawers)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function countsFor(array $spaces): array
    {
        if ([] === $spaces) {
            return [];
        }

        $slugs = array_map(static fn (SpaceId $space): string => $space->value, $spaces);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.slug, count(e.id) AS liczba
                FROM ws.spaces s
                LEFT JOIN ws.memory_entries e ON e.space_id = s.id
                WHERE s.slug IN (:slugs)
                GROUP BY s.slug
                SQL,
            ['slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );

        // Every requested space gets a key, including ones the join found nothing
        // for and ones that no longer exist: a missing key would read as "no
        // answer" where the answer is zero.
        $counts = array_fill_keys($slugs, 0);
        foreach ($rows as $row) {
            $counts[(string) $row['slug']] = (int) $row['liczba'];
        }

        return $counts;
    }

    public function transactional(\Closure $work): mixed
    {
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }
}
