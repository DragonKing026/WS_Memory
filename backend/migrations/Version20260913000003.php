<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two indexes the audit screen needs (TODO-008).
 *
 * `ws.audit_log` is the one table in this system that grows without bound, and the
 * administration screen is the only place that reads it whole — so the difference between
 * an index scan and a sequential scan here is the difference between a panel that keeps
 * working and one that gets slower every week until somebody notices.
 *
 * Measured on 20 000 rows before being written, with `EXPLAIN (ANALYZE, BUFFERS)`. The
 * numbers below are buffers touched, which is the part that grows with the table:
 *
 *   filter by space, 5 matching rows   seq scan, 340 buffers  →  bitmap scan, 4
 *   count of that filter               seq scan, 340          →  index-only scan, 5
 *   filter by actor, 1 matching row    seq scan, 340          →  BitmapOr, 5
 *
 * The pathological case is deliberately the one with FEW matches, because that is the
 * useful query — "what happened in this space", "what did this person do" — and it is the
 * one that degrades worst: with no usable index the planner must read the whole table to
 * discover that six rows match.
 *
 * **What is NOT here, and why.** The main listing sorts `created_at DESC` and needs no new
 * index: `idx_audit_created_at` from Version20260912000002 is scanned backwards, which a
 * btree does just as cheaply as forwards (5 buffers for a page of 100). Neither composite
 * index is declared `DESC` either, for the same reason — `(space_slug, created_at)` read
 * backwards within one slug gives newest-first, and an ASC declaration is what the Doctrine
 * mapping can express, so the entity and the database stay identical instead of differing
 * in a way that a future diff would report as drift.
 *
 * The actor index covers `actor_agent_token_id` alone; `actor_user_id` already had one. The
 * filter matches both columns with `OR`, and an `OR` where only one side is indexed is a
 * sequential scan — indexing the second side is what turns it into a BitmapOr.
 *
 * Written by hand like every migration here — the diff generator needs DBAL API absent in
 * this version (see Version20260912000001).
 */
final class Version20260913000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indeksy dziennika audytu: filtr po przestrzeni i po tokenie agenta';
    }

    public function up(Schema $schema): void
    {
        // Leading column is the equality, `created_at` second: that order lets one index
        // answer both halves of a filtered page — which rows match, and in what order they
        // come out — and lets `count(*)` for one space run index-only.
        $this->addSql('CREATE INDEX idx_audit_space ON ws.audit_log (space_slug, created_at)');

        $this->addSql('CREATE INDEX idx_audit_actor_token ON ws.audit_log (actor_agent_token_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ws.idx_audit_actor_token');
        $this->addSql('DROP INDEX ws.idx_audit_space');
    }
}
