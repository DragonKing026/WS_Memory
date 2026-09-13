<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puts the owner into the source-pair uniqueness.
 *
 * `uniq_entries_source` was `(source_replica, source_drawer_id)` — a pair the
 * publishing client supplies in full, with nothing tying it to who published it.
 * Two consequences, and the first is a hole rather than a wart:
 *
 * 1. **Somebody else's row could be taken over.** The republication path looks a
 *    binding up by that pair; naming another person's replica name and one of
 *    their local drawer ids returned their row, and the refresh then rewrote the
 *    palace drawer with the caller's text and moved the registry row into the
 *    caller's own space. Reported by code scanning on the pull request that
 *    introduced the bridge, before anybody could reach the endpoint.
 * 2. **Two people could not hold the same pair.** Replica names are chosen on the
 *    machine that generates them and nothing coordinates them, so two laptops
 *    picking the same name meant the first publisher permanently blocked the
 *    second — a failure that would look like data loss and be nearly impossible
 *    to diagnose.
 *
 * The lookup in DoctrineMemoryRegistry matches this index column for column.
 */
final class Version20260913000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unikalność pary źródłowej zawężona do właściciela wpisu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ws.uniq_entries_source');

        // Partial for the same reason as before: rows written on the server carry
        // no replica at all and there are going to be many of them.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_entries_source
                ON ws.memory_entries (author_user_id, source_replica, source_drawer_id)
                WHERE source_replica IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Going back re-creates the vulnerable shape, which is what "down" means
        // here. It can fail where two owners already hold the same pair — content
        // the narrower index made legal — and that failure is correct: the rows
        // would have to be reconciled by hand before the old index could exist.
        $this->addSql('DROP INDEX IF EXISTS ws.uniq_entries_source');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_entries_source
                ON ws.memory_entries (source_replica, source_drawer_id)
                WHERE source_replica IS NOT NULL
            SQL);
    }
}
