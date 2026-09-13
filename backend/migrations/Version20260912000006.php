<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for lexical search (D-029, D-030).
 *
 * Lexical search runs here rather than in the palace because the palace has no
 * lexical mode — `mempalace_search` takes a query, a wing, a room and a date
 * window, and nothing that would ask for exact terms (D-029). What we can search
 * is what we hold ourselves: the full content of document revisions, and the
 * title and tags of every registered memory entry.
 *
 * The configuration is `simple`, meaning tokenisation without stemming, and that
 * is a choice rather than a limitation. Postgres ships no Polish stemmer, but more
 * importantly this mode answers "where exactly does this name appear" — and for
 * `PalaceWing` or `Version20260912000003`, stemming would add wrong hits without
 * adding right ones (D-030). Inflection is the semantic mode's job.
 *
 * Two details that are easy to get wrong:
 *
 *   - the **two-argument** `to_tsvector('simple', ...)` is IMMUTABLE and therefore
 *     indexable; the one-argument form reads `default_text_search_config`, is only
 *     STABLE, and Postgres refuses it in an index;
 *   - the revision index covers **every** revision, not just current ones. A
 *     partial index would need a subquery over `ws.documents`, which is not
 *     allowed in an index predicate. Filtering to the current revision happens in
 *     the query, by joining on `documents.current_revision_id`.
 *
 * No index is added for "latest writes in a space": `idx_entries_space_created`
 * from Version20260912000003 already covers it. It is ascending, and a btree scans
 * backwards just as cheaply, so a second descending copy would cost writes and
 * disk for nothing.
 */
final class Version20260912000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wyszukiwanie leksykalne: indeksy GIN na treści rewizji oraz tytułach i tagach wpisów';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_revisions_content_fts
                ON ws.document_revisions USING GIN (to_tsvector('simple', content))
            SQL);

        // The title is what a person recognises a note by, and for everything that
        // is not a document it is the only text we hold — the body of a note or a
        // diary entry lives in the palace and nowhere else (D-029).
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_entries_title_fts
                ON ws.memory_entries USING GIN (to_tsvector('simple', title))
            SQL);

        // `jsonb_path_query_array` flattens the tag list into text the same way the
        // search query will, so the index is actually usable by it. Tags are how
        // the miner marks topics, and searching them lexically is the one way to
        // ask "what is tagged this" without guessing at wording.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_entries_tags_fts
                ON ws.memory_entries USING GIN (to_tsvector('simple', jsonb_path_query_array(tags, '$[*]')::text))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ws.idx_entries_tags_fts');
        $this->addSql('DROP INDEX ws.idx_entries_title_fts');
        $this->addSql('DROP INDEX ws.idx_revisions_content_fts');
    }
}
