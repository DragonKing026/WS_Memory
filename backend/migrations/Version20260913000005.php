<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The bridge from a local palace to the shared base (TODO-012).
 *
 * `ws.memory_entries` already carries every column this bridge needs —
 * `source_replica`, `source_drawer_id`, `publish_batch_id`, `content_hash`, the
 * partial unique index on the source pair and the `(space_id, content_hash)`
 * index — all of it laid down by Version20260912000003 while the registry itself
 * was being written. What was missing is everything AROUND those columns: the
 * batch a publication belongs to, the mapping that decides where it lands, and
 * the per-replica switch that turns the whole thing off.
 *
 * Three choices here decide how the bridge behaves and are easy to get wrong:
 *
 *   - `mirrors.is_confirmed` defaults to FALSE. A mapping is what makes content
 *     visible to the team (D-014), so it is the thing a human confirms — and an
 *     unconfirmed row must therefore route nothing anywhere. Defaulting it to
 *     true would turn "somebody proposed a mapping" into "the team can read it";
 *   - `publish_settings.auto_publish` defaults to TRUE, which is the opposite
 *     default and for the opposite reason: sending is the behaviour (D-014), and
 *     a knowledge base you have to remember to feed stays empty;
 *   - `publish_batches.space_id` is NOT NULL, so one batch belongs to exactly one
 *     space. A request carrying drawers from several wings is therefore split
 *     into one batch per target space (D-036): a batch is the unit of revert, and
 *     "undo what went to the team space" must not also undo what went into the
 *     private one.
 *
 * `reverted_at` and `status` are kept as separate facts rather than one. The
 * status is what the code reads; the timestamp is what a person reading the
 * journal a month later needs, and deriving one from the other would mean a
 * reverted batch with no idea when.
 */
final class Version20260913000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mostek z lokalnego pałaca: lustra, partie publikacji, ustawienia wysyłki';
    }

    public function up(Schema $schema): void
    {
        // ------------------------------------------------------------- mirrors

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.mirrors (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                source_replica VARCHAR(100) NOT NULL,
                source_wing VARCHAR(200) NOT NULL,
                space_id UUID NOT NULL,
                excluded_rooms JSONB DEFAULT '[]' NOT NULL,
                is_active BOOLEAN DEFAULT TRUE NOT NULL,
                is_confirmed BOOLEAN DEFAULT FALSE NOT NULL,
                paused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_drawer_filed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // One wing of one replica maps to at most one space. Two rows would make
        // "where does this wing land?" a question with two answers, and the
        // landing rule would have to pick — silently, on every publication.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_mirrors_source
                ON ws.mirrors (user_id, source_replica, source_wing)
            SQL);
        $this->addSql('CREATE INDEX idx_mirrors_space ON ws.mirrors (space_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.mirrors
                ADD CONSTRAINT fk_mirrors_user FOREIGN KEY (user_id)
                REFERENCES ws.users (id) ON DELETE CASCADE
            SQL);
        // RESTRICT for the space, CASCADE for the user, and the asymmetry is
        // deliberate. Deleting an account may take its mappings with it — they
        // describe that person's machine and mean nothing without them. A space
        // with published content is archived rather than dropped, exactly as
        // `memory_entries` already insists.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.mirrors
                ADD CONSTRAINT fk_mirrors_space FOREIGN KEY (space_id)
                REFERENCES ws.spaces (id) ON DELETE RESTRICT
            SQL);

        $this->addSql("COMMENT ON COLUMN ws.mirrors.paused_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.mirrors.last_synced_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.mirrors.last_drawer_filed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.mirrors.created_at IS '(DC2Type:datetime_immutable)'");

        // ---------------------------------------------------- publish_settings

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.publish_settings (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                source_replica VARCHAR(100) NOT NULL,
                auto_publish BOOLEAN DEFAULT TRUE NOT NULL,
                private_space_id UUID DEFAULT NULL,
                last_watermark TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_publish_settings_replica
                ON ws.publish_settings (user_id, source_replica)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_settings
                ADD CONSTRAINT fk_publish_settings_user FOREIGN KEY (user_id)
                REFERENCES ws.users (id) ON DELETE CASCADE
            SQL);
        // Nullable and SET NULL: `private_space_id` only records where unmapped
        // wings went, and losing that record must not block deleting a space the
        // administrator has already emptied.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_settings
                ADD CONSTRAINT fk_publish_settings_space FOREIGN KEY (private_space_id)
                REFERENCES ws.spaces (id) ON DELETE SET NULL
            SQL);

        $this->addSql("COMMENT ON COLUMN ws.publish_settings.last_watermark IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.publish_settings.updated_at IS '(DC2Type:datetime_immutable)'");

        // ----------------------------------------------------- publish_batches

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.publish_batches (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                agent_token_id UUID DEFAULT NULL,
                mirror_id UUID DEFAULT NULL,
                space_id UUID NOT NULL,
                source_replica VARCHAR(100) NOT NULL,
                mode VARCHAR(20) NOT NULL,
                drawer_count INT DEFAULT 0 NOT NULL,
                skipped_count INT DEFAULT 0 NOT NULL,
                skipped_reasons JSONB DEFAULT '[]' NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                reverted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_batches_user_created ON ws.publish_batches (user_id, created_at)');
        $this->addSql('CREATE INDEX idx_batches_space_created ON ws.publish_batches (space_id, created_at)');

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_batches
                ADD CONSTRAINT chk_batches_mode
                CHECK (mode IN ('selective', 'mirror'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_batches
                ADD CONSTRAINT chk_batches_status
                CHECK (status IN ('preview', 'applied', 'reverted'))
            SQL);
        // A reverted batch has a time of reverting and nothing else has one. The
        // journal is read to answer "what did I undo and when", and a status that
        // could disagree with its own timestamp would answer it wrongly.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_batches
                ADD CONSTRAINT chk_batches_reverted_at
                CHECK ((status = 'reverted') = (reverted_at IS NOT NULL))
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_batches
                ADD CONSTRAINT fk_batches_user FOREIGN KEY (user_id)
                REFERENCES ws.users (id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_batches
                ADD CONSTRAINT fk_batches_space FOREIGN KEY (space_id)
                REFERENCES ws.spaces (id) ON DELETE RESTRICT
            SQL);
        // SET NULL rather than CASCADE: deleting a mapping must not delete the
        // record of what it once published. The batch is the unit of revert, and
        // a revert nobody can find any more is a revert nobody can perform.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.publish_batches
                ADD CONSTRAINT fk_batches_mirror FOREIGN KEY (mirror_id)
                REFERENCES ws.mirrors (id) ON DELETE SET NULL
            SQL);

        $this->addSql("COMMENT ON COLUMN ws.publish_batches.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.publish_batches.reverted_at IS '(DC2Type:datetime_immutable)'");

        // --------------------------------------- memory_entries -> the batch it came in

        // The column has been there since Version20260912000003; only now is
        // there a table for it to point at. SET NULL, because a batch row is the
        // history of a publication and a published drawer must outlive it: were
        // this CASCADE, tidying up the journal would silently delete registry
        // rows and with them the only record of which space that content is in.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.memory_entries
                ADD CONSTRAINT fk_entries_batch FOREIGN KEY (publish_batch_id)
                REFERENCES ws.publish_batches (id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_entries_batch ON ws.memory_entries (publish_batch_id)
                WHERE publish_batch_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ws.memory_entries DROP CONSTRAINT fk_entries_batch');
        $this->addSql('DROP INDEX ws.idx_entries_batch');
        $this->addSql('DROP TABLE ws.publish_batches');
        $this->addSql('DROP TABLE ws.publish_settings');
        $this->addSql('DROP TABLE ws.mirrors');
    }
}
