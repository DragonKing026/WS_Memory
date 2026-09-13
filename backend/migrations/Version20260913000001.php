<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * State of the last dependency check (TODO-015).
 *
 * One row per dependency, keyed by name — not a history. The question this table
 * answers is "what is the situation now", and the trail of who checked and when
 * already has a home in `ws.audit_log`, which is append-only.
 *
 * Every version column is nullable, and each null means something specific:
 *
 *   - `installed_version` — the running service did not tell us. The palace has no
 *     `/version` route, so this comes from the MCP handshake, which needs the
 *     service to be up;
 *   - `pinned_version` — no `MEMPALACE_VERSION` is configured;
 *   - `latest_version` — no check has ever succeeded. Notably NOT "there is
 *     nothing newer": a failed check leaves the previous value in place, which is
 *     why it is not overwritten with null on failure.
 *
 * `check_problem` is TEXT and holds a sentence for a person, because that sentence
 * is shown verbatim in the panel. It carries the distinction the whole task rests
 * on: filled means "nie udało się sprawdzić", null means the numbers are good as of
 * `checked_at`. Without it, an unreachable PyPI is indistinguishable from being up
 * to date — the debt that grows in silence.
 *
 * `checked_at` is the time of the last SUCCESSFUL check, deliberately not of the
 * last attempt: paired with `check_problem` it lets the panel say "ostatnio udało
 * się we wtorek", which is the sentence an administrator can act on.
 *
 * Widths: `name` is an identifier we choose ourselves, `varchar(32)` is generous
 * for a version even with four components. Written by hand like every migration
 * here — the diff generator needs DBAL API absent in this version (see
 * Version20260912000001).
 */
final class Version20260913000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stan sprawdzania zależności: tabela ws.dependency_state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.dependency_state (
                name VARCHAR(64) NOT NULL,
                installed_version VARCHAR(32) DEFAULT NULL,
                pinned_version VARCHAR(32) DEFAULT NULL,
                latest_version VARCHAR(32) DEFAULT NULL,
                checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                check_problem TEXT DEFAULT NULL,
                PRIMARY KEY(name)
            )
            SQL);

        // Said in the schema rather than only in this file: the next person to
        // read the table with psql sees the trap before stepping in it.
        $this->addSql(
            "COMMENT ON COLUMN ws.dependency_state.checked_at IS "
            . "'Moment ostatniego UDANEGO sprawdzenia, nie ostatniej próby.'"
        );
        $this->addSql(
            "COMMENT ON COLUMN ws.dependency_state.check_problem IS "
            . "'Powód nieudanego sprawdzenia, po polsku, pokazywany w panelu. NULL = sprawdzenie się udało.'"
        );
        $this->addSql(
            "COMMENT ON COLUMN ws.dependency_state.latest_version IS "
            . "'Ostatnia znana najnowsza wersja. Nieudane sprawdzenie jej NIE kasuje.'"
        );

        // No index beyond the primary key. The table holds one row per dependency
        // — a handful, forever — and every query here is a lookup by name.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ws.dependency_state');
    }
}
