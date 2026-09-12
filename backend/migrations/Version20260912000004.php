<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agent tokens — the credential an AI agent authenticates with at /mcp.
 *
 * Not a JWT, and that is a decision rather than an omission (docs/03): these
 * live for months, must die the moment somebody says so, carry a scope that only
 * narrows their owner's permissions, and have to show when they were last used so
 * dead ones can be spotted. A JWT is none of those things — it is valid until it
 * expires and nothing can recall it.
 *
 * Two columns deserve a note:
 *
 *   - `space_scope` is NULL for "everything the owner may see" and a JSON array
 *     of slugs otherwise. NULL is not "no spaces": a token with an empty array
 *     reaches nothing, which is a useful thing to be able to express;
 *   - `calls_in_window` and `window_started_at` carry the rate limit (D-022).
 *     They live here rather than in a cache because the write that records them
 *     is the write that records `last_used_at` — one statement, no new
 *     dependency, and correct across several backend containers.
 */
final class Version20260912000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tokeny agentów AI do gatewaya MCP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.agent_tokens (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                label VARCHAR(120) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                space_scope JSONB DEFAULT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_used_ip VARCHAR(45) DEFAULT NULL,
                calls_in_window INT DEFAULT 0 NOT NULL,
                window_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // Unique on the hash: a collision must be a database error, never two
        // tokens answering to the same secret.
        $this->addSql('CREATE UNIQUE INDEX uniq_agent_tokens_hash ON ws.agent_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_agent_tokens_user ON ws.agent_tokens (user_id)');

        // CASCADE, unlike everywhere else in this schema. A token carries no
        // history of its own — what it did is recorded in `audit_log` and
        // `memory_entries`, neither of which has a foreign key to it — so
        // deleting an account may take its credentials with it without erasing
        // any trace of what they did.
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.agent_tokens
                ADD CONSTRAINT fk_agent_tokens_user FOREIGN KEY (user_id)
                REFERENCES ws.users (id) ON DELETE CASCADE
            SQL);

        foreach (['created_at', 'expires_at', 'revoked_at', 'last_used_at', 'window_started_at'] as $column) {
            $this->addSql("COMMENT ON COLUMN ws.agent_tokens.{$column} IS '(DC2Type:datetime_immutable)'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ws.agent_tokens');
    }
}
