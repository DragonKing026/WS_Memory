<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Canonical documentation: documents, their full revision history, and the queue.
 *
 * Revisions hold **complete content, not diffs**. A chain of diffs is only as
 * trustworthy as its weakest link — one damaged link invalidates every version
 * after it — and the history of company documentation is precisely the thing that
 * must not become unreadable. Full copies cost more disk and are worth it; the
 * diff is computed when somebody asks for one.
 *
 * Three constraints here carry weight rather than tidiness:
 *
 *   - `document_revisions` has a CHECK allowing **exactly one** author, human or
 *     agent token. A revision with neither is unattributable, one with both is a
 *     lie about who wrote it;
 *   - `documents.current_revision_id` is a **composite** foreign key on
 *     `(current_revision_id, id)`, so pointing a document at another document's
 *     revision is unrepresentable rather than merely wrong. A plain foreign key
 *     would permit it, and nothing would notice until a reader saw somebody
 *     else's text;
 *   - `(document_id, number)` is unique, so revision numbers cannot repeat within
 *     a document. That is what makes "revision 3" a usable reference.
 */
final class Version20260912000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wiki: dokumenty, rewizje i kolejka propozycji';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.documents (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
                slug VARCHAR(160) NOT NULL,
                title VARCHAR(300) NOT NULL,
                status VARCHAR(20) DEFAULT 'draft' NOT NULL,
                current_revision_id UUID DEFAULT NULL,
                authored_by_ai BOOLEAN DEFAULT false NOT NULL,
                verified_by UUID DEFAULT NULL,
                verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.document_revisions (
                id UUID NOT NULL,
                document_id UUID NOT NULL,
                number INT NOT NULL,
                content TEXT NOT NULL,
                title_at_revision VARCHAR(300) NOT NULL,
                author_user_id UUID DEFAULT NULL,
                author_agent_token_id UUID DEFAULT NULL,
                change_note VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_documents_space_slug ON ws.documents (space_id, slug)');
        $this->addSql('CREATE INDEX idx_documents_space ON ws.documents (space_id)');
        $this->addSql('CREATE INDEX idx_documents_status ON ws.documents (status)');

        $this->addSql('CREATE UNIQUE INDEX uniq_revisions_document_number ON ws.document_revisions (document_id, number)');
        $this->addSql('CREATE INDEX idx_revisions_document ON ws.document_revisions (document_id)');
        // Needed as the target of the composite foreign key below.
        $this->addSql('CREATE UNIQUE INDEX uniq_revisions_id_document ON ws.document_revisions (id, document_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.document_revisions
                ADD CONSTRAINT chk_revisions_single_author
                CHECK ((author_user_id IS NOT NULL) <> (author_agent_token_id IS NOT NULL))
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.documents
                ADD CONSTRAINT fk_documents_space FOREIGN KEY (space_id)
                REFERENCES ws.spaces (id) ON DELETE RESTRICT
            SQL);
        // RESTRICT on the author: deleting an account must not be able to erase
        // who wrote what. Accounts are deactivated, not deleted.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.documents
                ADD CONSTRAINT fk_documents_verifier FOREIGN KEY (verified_by)
                REFERENCES ws.users (id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.document_revisions
                ADD CONSTRAINT fk_revisions_document FOREIGN KEY (document_id)
                REFERENCES ws.documents (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.document_revisions
                ADD CONSTRAINT fk_revisions_author FOREIGN KEY (author_user_id)
                REFERENCES ws.users (id) ON DELETE RESTRICT
            SQL);

        // The composite key. DEFERRABLE because a document and its first revision
        // are written in one transaction and each references the other: checked at
        // COMMIT, the pair is consistent; checked per statement, neither could be
        // inserted first.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.documents
                ADD CONSTRAINT fk_documents_current_revision
                FOREIGN KEY (current_revision_id, id)
                REFERENCES ws.document_revisions (id, document_id)
                DEFERRABLE INITIALLY DEFERRED
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.documents
                ADD CONSTRAINT chk_documents_status
                CHECK (status IN ('draft', 'published'))
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.proposals (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
                slug VARCHAR(160) DEFAULT NULL,
                title VARCHAR(300) NOT NULL,
                content TEXT NOT NULL,
                author_agent_token_id UUID DEFAULT NULL,
                author_user_id UUID DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'pending' NOT NULL,
                reviewed_by UUID DEFAULT NULL,
                reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                review_note VARCHAR(500) DEFAULT NULL,
                resulting_document_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_proposals_space_status ON ws.proposals (space_id, status)');
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.proposals
                ADD CONSTRAINT chk_proposals_status
                CHECK (status IN ('pending', 'accepted', 'rejected'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.proposals
                ADD CONSTRAINT fk_proposals_space FOREIGN KEY (space_id)
                REFERENCES ws.spaces (id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.proposals
                ADD CONSTRAINT fk_proposals_reviewer FOREIGN KEY (reviewed_by)
                REFERENCES ws.users (id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.proposals
                ADD CONSTRAINT fk_proposals_document FOREIGN KEY (resulting_document_id)
                REFERENCES ws.documents (id) ON DELETE SET NULL
            SQL);

        // Deferred from TODO-003, where `documents` did not exist yet.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.memory_entries
                ADD CONSTRAINT fk_entries_document FOREIGN KEY (document_id)
                REFERENCES ws.documents (id) ON DELETE SET NULL
            SQL);
        // One registry row per document: publishing a new revision updates the
        // drawer in place rather than filing a second one.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_entries_document ON ws.memory_entries (document_id)
                WHERE document_id IS NOT NULL
            SQL);

        foreach (['created_at', 'updated_at', 'archived_at', 'verified_at'] as $column) {
            $this->addSql("COMMENT ON COLUMN ws.documents.{$column} IS '(DC2Type:datetime_immutable)'");
        }
        $this->addSql("COMMENT ON COLUMN ws.document_revisions.created_at IS '(DC2Type:datetime_immutable)'");
        foreach (['created_at', 'reviewed_at'] as $column) {
            $this->addSql("COMMENT ON COLUMN ws.proposals.{$column} IS '(DC2Type:datetime_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ws.uniq_entries_document');
        $this->addSql('ALTER TABLE ws.memory_entries DROP CONSTRAINT fk_entries_document');
        $this->addSql('DROP TABLE ws.proposals');
        $this->addSql('ALTER TABLE ws.documents DROP CONSTRAINT fk_documents_current_revision');
        $this->addSql('DROP TABLE ws.document_revisions');
        $this->addSql('DROP TABLE ws.documents');
    }
}
