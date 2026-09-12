<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accounts, invitations, spaces, roles and the audit trail.
 *
 * Written by hand, like every migration here: with `schema_filter` in place the
 * diff generator needs DBAL ^4.5 and the stable release is 4.4.4
 * (docs/05-deployment.md).
 *
 * Three constraints in this migration are load-bearing rather than tidy:
 *
 *   - the composite key on `space_members` makes two roles for one person in
 *     one space unrepresentable, so "which role wins" cannot be asked;
 *   - `audit_log` references its actor by plain id, not by foreign key, so
 *     deactivating an account never touches the record of what it did;
 *   - `ON DELETE CASCADE` on memberships is safe precisely because
 *     memberships carry no history — the audit trail does.
 */
final class Version20260912000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Konta, zaproszenia, przestrzenie, role i dziennik audytu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.users (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                roles JSONB NOT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON ws.users (email)');
        $this->addSql('CREATE INDEX idx_users_email ON ws.users (email)');

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.spaces (
                id UUID NOT NULL,
                slug VARCHAR(100) NOT NULL,
                name VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                palace_wing VARCHAR(150) NOT NULL,
                palace_namespace VARCHAR(150) DEFAULT NULL,
                is_private BOOLEAN DEFAULT false NOT NULL,
                requires_proposal BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_spaces_slug ON ws.spaces (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.space_members (
                space_id UUID NOT NULL,
                user_id UUID NOT NULL,
                role VARCHAR(20) NOT NULL,
                added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                added_by UUID DEFAULT NULL,
                PRIMARY KEY(space_id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_members_user ON ws.space_members (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.space_members
                ADD CONSTRAINT fk_members_space FOREIGN KEY (space_id)
                REFERENCES ws.spaces (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.space_members
                ADD CONSTRAINT fk_members_user FOREIGN KEY (user_id)
                REFERENCES ws.users (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.space_members
                ADD CONSTRAINT fk_members_added_by FOREIGN KEY (added_by)
                REFERENCES ws.users (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.invitations (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                invited_by UUID DEFAULT NULL,
                grants_global_admin BOOLEAN DEFAULT false NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        // Unique on the hash: an accidental token collision must be a database
        // error, never two invitations answering to the same link.
        $this->addSql('CREATE UNIQUE INDEX uniq_invitations_token ON ws.invitations (token_hash)');
        $this->addSql('CREATE INDEX idx_invitations_token ON ws.invitations (token_hash)');
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.invitations
                ADD CONSTRAINT fk_invitations_inviter FOREIGN KEY (invited_by)
                REFERENCES ws.users (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.audit_log (
                id UUID NOT NULL,
                actor_user_id UUID DEFAULT NULL,
                actor_agent_token_id UUID DEFAULT NULL,
                action VARCHAR(60) NOT NULL,
                space_slug VARCHAR(100) DEFAULT NULL,
                target JSONB NOT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_audit_created_at ON ws.audit_log (created_at)');
        $this->addSql('CREATE INDEX idx_audit_actor ON ws.audit_log (actor_user_id)');
        $this->addSql('CREATE INDEX idx_audit_action ON ws.audit_log (action)');

        foreach (['users', 'spaces', 'invitations', 'audit_log'] as $table) {
            $this->addSql("COMMENT ON COLUMN ws.{$table}.created_at IS '(DC2Type:datetime_immutable)'");
        }
        $this->addSql("COMMENT ON COLUMN ws.users.last_login_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.space_members.added_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.invitations.expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ws.invitations.accepted_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ws.audit_log');
        $this->addSql('DROP TABLE ws.invitations');
        $this->addSql('DROP TABLE ws.space_members');
        $this->addSql('DROP TABLE ws.spaces');
        $this->addSql('DROP TABLE ws.users');
    }
}
