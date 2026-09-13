<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\LexicalIndex;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\SearchMode;
use App\Domain\Search\SearchHit;
use App\Domain\Search\Snippet;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Adapter: exact-term search on PostgreSQL full text (D-029, D-030).
 *
 * Two sources, unioned, because we hold two different amounts of text:
 *
 *   - documents, through the current revision, searched over their whole body;
 *   - every other entry, searched over its title and tags — the body of a note
 *     or a diary entry is in the palace and unreachable from here.
 *
 * The split is by `document_id`, so nothing is counted twice: a published
 * document has a registry row too, and that row is deliberately excluded from
 * the second branch.
 *
 * Documents are matched even when they have no drawer yet. Publishing is done by
 * a worker a moment after the save (PublishDocumentHandler), and a person who has
 * just written a page is exactly the person about to search for it.
 */
final readonly class DoctrineLexicalIndex implements LexicalIndex
{
    /**
     * Sentinels wrapping matched words in ts_headline output.
     *
     * STX and ETX rather than the default `<b>`/`</b>`: the content is Markdown
     * written by people, so `<b>` can occur in it literally, and stripping it
     * afterwards would corrupt the text. These two control characters cannot.
     * They never reach the API — Snippet::fromMarked() turns them into structure.
     */
    private const MARK_START = "\x02";
    private const MARK_END = "\x03";

    private const HEADLINE_OPTIONS = 'StartSel=' . self::MARK_START . ',StopSel=' . self::MARK_END
        . ',MaxFragments=1,MaxWords=40,MinWords=15';

    public function __construct(private Connection $connection)
    {
    }

    public function search(array $spaces, MemoryQuery $query): array
    {
        // The port types this as a non-empty list, so PHPStan calls the check
        // redundant — but a PHPDoc type is not enforced at runtime, and what it
        // guards here is the difference between "these spaces" and "everybody's
        // content" (inviolable rule 3). Kept, deliberately.
        // @phpstan-ignore identical.alwaysFalse
        if ([] === $spaces) {
            throw new \InvalidArgumentException('Wyszukiwanie leksykalne wymaga wskazania przestrzeni.');
        }

        if (!self::hasSearchableCharacters($query->text)) {
            // Punctuation only. The palace would answer something for it; here
            // there is nothing to match, and an empty answer is the honest one.
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            self::SQL,
            [
                'text' => $query->text,
                'headline' => self::HEADLINE_OPTIONS,
                'slugs' => array_map(static fn (SpaceId $s): string => $s->value, $spaces),
                'kind' => $query->kind?->value,
                'since' => $query->since?->format('Y-m-d H:i:s'),
                'before' => $query->before?->format('Y-m-d H:i:s'),
                'limit' => $query->limit,
            ],
            [
                'slugs' => ArrayParameterType::STRING,
            ],
        );

        return array_map(self::hitFrom(...), $rows);
    }

    /**
     * Whether there is anything here a text search could match.
     *
     * Only a fast path. The query itself is built in SQL, so this decides nothing
     * about correctness — it just avoids a round trip for a box holding `???`.
     */
    private static function hasSearchableCharacters(string $text): bool
    {
        return 1 === preg_match('/[\p{L}\p{N}]/u', $text);
    }

    /** @param array<string, mixed> $row */
    private static function hitFrom(array $row): SearchHit
    {
        $drawer = self::stringOrNull($row['drawer_id'] ?? null);
        $score = isset($row['score']) ? (float) $row['score'] : null;

        return new SearchHit(
            space: new SpaceId((string) $row['space_slug']),
            kind: MemoryKind::from((string) $row['kind']),
            title: (string) $row['title'],
            snippet: Snippet::fromMarked((string) $row['snippet'], self::MARK_START, self::MARK_END),
            mode: SearchMode::Lexical,
            score: $score,
            // Ranking is done in SQL and the threshold with it: a row that came
            // back at all matched every token, so nothing here is "weak" in the
            // sense the semantic mode means it.
            weak: false,
            byAi: (bool) $row['by_ai'],
            verified: (bool) $row['verified'],
            drawer: null === $drawer ? null : new DrawerId($drawer),
            documentSlug: self::stringOrNull($row['document_slug'] ?? null),
            at: self::dateOrNull($row['at'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Both branches, ranked together.
     *
     * **The query is tokenised by the same parser as the content**, by running the
     * user's text through `to_tsvector` and rebuilding a prefix query from the
     * lexemes it produced. Tokenising in PHP instead looks equivalent and is not:
     * `simple` reads `D-029` as the two lexemes `d` and `-029` — the number keeps
     * its sign — so a hand-rolled tokeniser yielding `029` matches nothing, and
     * searching for a decision or ticket id silently fails. Which is precisely
     * what this mode exists for.
     *
     * **Only the last word gets `:*`.** That is the one being typed; the ones
     * before it are finished words the user meant literally. Prefixing all of
     * them sounds harmless until a query like `D-029` turns into `d:*`, which
     * matches `do`, `dokument` and `dostęp` — noise in the very mode whose job is
     * to be exact. The order comes from the lexemes' positions, because `unnest`
     * over a tsvector yields them alphabetically, not as they were typed.
     *
     * `quote_literal` is what makes it safe: every lexeme reaches the query as a
     * quoted string, so no input can be read as tsquery syntax.
     *
     * `to_tsvector` is repeated rather than aliased because the expression has to
     * match the index definition character for character for Postgres to use it —
     * a CTE or a lateral join here quietly turns a GIN index scan into a
     * sequential scan over every revision in the base.
     */
    private const SQL = <<<'SQL'
        WITH q AS (
            SELECT to_tsquery('simple', (
                SELECT string_agg(
                    quote_literal(lexeme) || CASE WHEN positions[1] = last_pos THEN ':*' ELSE '' END,
                    ' & ' ORDER BY positions[1]
                )
                FROM (
                    SELECT lexeme, positions, max(positions[1]) OVER () AS last_pos
                    FROM unnest(to_tsvector('simple', :text))
                ) tokens
            )) AS query
        )

        SELECT * FROM (
            SELECT
                'document'                                          AS kind,
                s.slug                                              AS space_slug,
                d.title                                             AS title,
                d.slug                                              AS document_slug,
                e.drawer_id                                         AS drawer_id,
                d.authored_by_ai                                    AS by_ai,
                (d.verified_at IS NOT NULL)                         AS verified,
                d.updated_at                                        AS at,
                ts_rank(to_tsvector('simple', r.content), q.query)  AS score,
                ts_headline('simple', r.content, q.query, :headline) AS snippet
            FROM ws.documents d
            CROSS JOIN q
            JOIN ws.document_revisions r ON r.id = d.current_revision_id
            JOIN ws.spaces s             ON s.id = d.space_id
            LEFT JOIN ws.memory_entries e ON e.document_id = d.id
            WHERE s.slug IN (:slugs)
              AND d.archived_at IS NULL
              AND to_tsvector('simple', r.content) @@ q.query
              AND (:kind::text IS NULL OR :kind = 'document')
              AND (:since::timestamp IS NULL OR d.updated_at >= :since)
              AND (:before::timestamp IS NULL OR d.updated_at < :before)

            UNION ALL

            SELECT
                e.kind                                              AS kind,
                s.slug                                              AS space_slug,
                e.title                                             AS title,
                NULL                                                AS document_slug,
                e.drawer_id                                         AS drawer_id,
                (e.author_agent_token_id IS NOT NULL)               AS by_ai,
                FALSE                                               AS verified,
                e.created_at                                        AS at,
                ts_rank(to_tsvector('simple', e.title), q.query)    AS score,
                e.title                                             AS snippet
            FROM ws.memory_entries e
            CROSS JOIN q
            JOIN ws.spaces s ON s.id = e.space_id
            WHERE s.slug IN (:slugs)
              -- Documents are the first branch's business; without this they
              -- would appear twice, once with their body and once with a title.
              AND e.document_id IS NULL
              AND (
                    to_tsvector('simple', e.title) @@ q.query
                 OR to_tsvector('simple', jsonb_path_query_array(e.tags, '$[*]')::text) @@ q.query
              )
              AND (:kind::text IS NULL OR e.kind = :kind)
              AND (:since::timestamp IS NULL OR e.created_at >= :since)
              AND (:before::timestamp IS NULL OR e.created_at < :before)
        ) AS hits
        ORDER BY score DESC, at DESC NULLS LAST
        LIMIT :limit
        SQL;
}
