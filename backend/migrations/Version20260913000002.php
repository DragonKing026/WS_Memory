<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update orders and the agent's pulse (TODO-015, D-032).
 *
 * These two tables are the whole conversation between the web application and the
 * host. The backend never runs anything: it writes a row here, and a script on the
 * host — outside every container, the only place that may talk to Docker — picks it
 * up. So this schema is not bookkeeping around an operation, it IS the channel, and
 * the constraints below are the part of it that cannot be talked out of.
 *
 * `ws.dependency_updates` is append-only in spirit: one row per order, never
 * rewritten except to move it along its lifecycle (`pending` → `running` →
 * `succeeded`/`failed`) and to attach the log the agent hands back. That log is why
 * the table holds history at all rather than one row per dependency like
 * `ws.dependency_state`: a failed update's log is the only trace of a broken
 * semantic test, and a broken semantic test is silent (D-003).
 *
 * Three things here are worth reading before changing anything:
 *
 * **The partial unique index.** At most one order per dependency may be `pending` or
 * `running`. The application checks this too, and the check in the application is
 * the one that produces a helpful 409 — but two administrators clicking at the same
 * millisecond both pass that check, and the loser has to break against the database
 * rather than queue a second rebuild of the same image behind the first. Terminal
 * rows are deliberately outside the index: the constraint is about work in flight,
 * not about how many times MemPalace was ever updated.
 *
 * **`status` is constrained in the database.** The set is closed, small, and read by
 * the frontend as an enum; a typo in a future `UPDATE` would otherwise produce a row
 * that no code path can interpret and that the partial index no longer holds — an
 * order invisible to the agent and blocking nothing, which is the worst of both.
 *
 * **`requested_by` restricts deletion.** An infrastructure change carries the
 * identity of whoever ordered it, and that identity must not become deletable by
 * deleting the account — the same rule `ws.audit_log` already sets.
 *
 * `ws.updater_heartbeat` holds exactly one row, forced by `CHECK (id = 1)`. It is
 * the only evidence the panel has that an agent exists at all; without it the panel
 * must say so instead of offering a button that writes an order nobody will ever
 * take. A single row rather than a history because the only question asked is "when
 * did we last hear from it" — a log of pulses would be a row a minute, forever, to
 * answer a question one timestamp answers.
 *
 * Written by hand like every migration here — the diff generator needs DBAL API
 * absent in this version (see Version20260912000001).
 */
final class Version20260913000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zlecenia aktualizacji zależności i puls agenta na hoście';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.dependency_updates (
                id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                from_version VARCHAR(32) DEFAULT NULL,
                to_version VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL,
                requested_by UUID NOT NULL,
                requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                log TEXT DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.dependency_updates
                ADD CONSTRAINT fk_dependency_updates_requested_by
                FOREIGN KEY (requested_by) REFERENCES ws.users (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.dependency_updates
                ADD CONSTRAINT chk_dependency_updates_status
                CHECK (status IN ('pending', 'running', 'succeeded', 'failed'))
            SQL);

        // The agent's own lookup: "is there anything to take for this dependency".
        $this->addSql(
            'CREATE INDEX idx_dependency_updates_name_status ON ws.dependency_updates (name, status)'
        );
        // The panel's: the newest orders first, which is the only order it ever
        // wants — most recent as the headline, the five before it as history.
        $this->addSql(
            'CREATE INDEX idx_dependency_updates_requested_at ON ws.dependency_updates (requested_at DESC)'
        );

        // The race-stopper. See the class comment: the application's own check is
        // the one that explains itself, this one is the one that cannot be lost.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_dependency_updates_in_flight
                ON ws.dependency_updates (name)
                WHERE status IN ('pending', 'running')
            SQL);

        $this->addSql(
            "COMMENT ON COLUMN ws.dependency_updates.from_version IS "
            . "'Wersja, którą aplikacja WIDZIAŁA jako działającą w chwili zlecenia. NULL, gdy nie dało się jej ustalić. "
            . "Agent wycofuje się do wersji z .env, nie do tej.'"
        );
        $this->addSql(
            "COMMENT ON COLUMN ws.dependency_updates.log IS "
            . "'Dziennik przebiegu od agenta: kopia zapasowa, przebudowa, test semantyki. "
            . "NULL, dopóki agent nie zgłosił wyniku.'"
        );
        $this->addSql(
            "COMMENT ON COLUMN ws.dependency_updates.started_at IS "
            . "'Moment podjęcia zlecenia przez agenta. Zlecenie running bez wyniku po 45 minutach jest domykane jako failed.'"
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.updater_heartbeat (
                id SMALLINT NOT NULL,
                seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT chk_updater_heartbeat_singleton CHECK (id = 1)
            )
            SQL);

        $this->addSql(
            "COMMENT ON TABLE ws.updater_heartbeat IS "
            . "'Jeden wiersz: kiedy agent aktualizacji na hoście ostatnio się zgłosił. "
            . "Brak wiersza = agenta nigdy nie zainstalowano, a panel ma to powiedzieć wprost.'"
        );
    }

    public function down(Schema $schema): void
    {
        // The index and the constraints go with the table; naming them here would
        // only be a second list to keep in step with the one above.
        $this->addSql('DROP TABLE ws.dependency_updates');
        $this->addSql('DROP TABLE ws.updater_heartbeat');
    }
}
