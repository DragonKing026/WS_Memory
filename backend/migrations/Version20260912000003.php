<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The registry of everything of ours that lives in the palace.
 *
 * The table looks redundant — the palace could answer "which wing is this
 * drawer in?" itself — until you ask where permissions are decided. They are
 * decided here, in SQL, BEFORE the semantic query runs, because filtering the
 * rows that come back from the palace is a leak rather than a permission
 * (inviolable rule 3). Listing, statistics and audit then come for free.
 *
 * Three choices in this migration are deliberate and easy to get wrong:
 *
 *   - `drawer_id` is UNIQUE. One piece of content belongs to exactly one space,
 *     so "which space is this in?" cannot have two answers;
 *   - `(space_id, content_hash)` is indexed but NOT unique. Skipping duplicates
 *     is a publishing policy (D-010), not an invariant of the data: two people
 *     may legitimately record the same sentence, and a constraint here would
 *     turn that into a failed write;
 *   - `author_agent_token_id` and `document_id` carry no foreign key, because
 *     the tables they point at arrive with TODO-004 and TODO-005. `audit_log`
 *     already sets that precedent for a good reason: a record of who did what
 *     must not be deletable by deleting the credential that did it.
 */
final class Version20260912000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rejestr treści w pałacu (ws.memory_entries)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.memory_entries (
                id UUID NOT NULL,
                drawer_id VARCHAR(255) NOT NULL,
                space_id UUID NOT NULL,
                kind VARCHAR(20) NOT NULL,
                author_user_id UUID NOT NULL,
                author_agent_token_id UUID DEFAULT NULL,
                document_id UUID DEFAULT NULL,
                title VARCHAR(500) NOT NULL,
                tags JSONB DEFAULT '[]' NOT NULL,
                content_hash CHAR(64) NOT NULL,
                source_replica VARCHAR(100) DEFAULT NULL,
                source_drawer_id VARCHAR(255) DEFAULT NULL,
                publish_batch_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_entries_drawer ON ws.memory_entries (drawer_id)');
        $this->addSql('CREATE INDEX idx_entries_space_created ON ws.memory_entries (space_id, created_at)');
        $this->addSql('CREATE INDEX idx_entries_kind ON ws.memory_entries (kind)');
        $this->addSql('CREATE INDEX idx_entries_dedup ON ws.memory_entries (space_id, content_hash)');

        // Republishing the same local drawer must update its row, not add a
        // second one (D-010). Partial, because rows created on the server carry
        // no replica at all and there are going to be many of them.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_entries_source ON ws.memory_entries (source_replica, source_drawer_id)
                WHERE source_replica IS NOT NULL
            SQL);

        // The kinds are fixed and few. A check constraint rather than an enum
        // type: adding a kind then costs one migration instead of an ALTER TYPE
        // that cannot run inside a transaction on older servers.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.memory_entries
                ADD CONSTRAINT chk_entries_kind
                CHECK (kind IN ('note', 'document', 'diary', 'kg_fact', 'transcript'))
            SQL);

        // RESTRICT, not CASCADE: deleting a space would leave its drawers in the
        // palace with nothing pointing at them, and content the registry does not
        // know is content nobody can ever read again. A space with history is
        // archived, never dropped.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.memory_entries
                ADD CONSTRAINT fk_entries_space FOREIGN KEY (space_id)
                REFERENCES ws.spaces (id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.memory_entries
                ADD CONSTRAINT fk_entries_author FOREIGN KEY (author_user_id)
                REFERENCES ws.users (id) ON DELETE RESTRICT
            SQL);

        $this->addSql("COMMENT ON COLUMN ws.memory_entries.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ws.memory_entries');
    }
}
