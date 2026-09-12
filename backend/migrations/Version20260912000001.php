<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pierwsza migracja: kolejka zadań w schemacie ws.
 *
 * Napisana ręcznie, nie wygenerowana. Powód: przy włączonym `schema_filter`
 * generator różnic wymaga API, którego nie ma w DBAL 4.4 (potrzeba ^4.5).
 * Filtr zostaje, bo chroni przed czymś gorszym — bez niego Doctrine widzi
 * tabele MemPalace w schemacie `palace` i przy pierwszej migracji proponuje
 * ich usunięcie.
 *
 * Kolejka trafia do bazy zamiast do osobnej usługi (RabbitMQ, Redis): jedna
 * rzecz mniej do postawienia i backupowania, a przy kilkunastu osobach
 * Postgres udźwignie to bez zadyszki.
 */
final class Version20260912000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kolejka zadań (Messenger) w schemacie ws';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ws.messenger_messages (
                id BIGSERIAL NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // Indeksy pod dokładnie te zapytania, które wykonuje konsument:
        // „daj następne zadanie z tej kolejki, dostępne teraz, nieodebrane".
        $this->addSql('CREATE INDEX idx_messenger_queue_name ON ws.messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX idx_messenger_available_at ON ws.messenger_messages (available_at)');
        $this->addSql('CREATE INDEX idx_messenger_delivered_at ON ws.messenger_messages (delivered_at)');

        $this->addSql(
            "COMMENT ON COLUMN ws.messenger_messages.created_at IS '(DC2Type:datetime_immutable)'"
        );
        $this->addSql(
            "COMMENT ON COLUMN ws.messenger_messages.available_at IS '(DC2Type:datetime_immutable)'"
        );
        $this->addSql(
            "COMMENT ON COLUMN ws.messenger_messages.delivered_at IS '(DC2Type:datetime_immutable)'"
        );

        // LISTEN/NOTIFY: konsument budzi się natychmiast po wstawieniu
        // zadania, zamiast odpytywać bazę co sekundę. Przy wysyłce z lokalnych
        // pałaców (D-014) zadania przychodzą nieregularnie, więc odpytywanie
        // byłoby w większości pustym przebiegiem.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION ws.notify_messenger_messages() RETURNS TRIGGER AS $$
                BEGIN
                    PERFORM pg_notify('ws_messenger_messages', NEW.queue_name::text);
                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER notify_trigger
                AFTER INSERT OR UPDATE ON ws.messenger_messages
                FOR EACH ROW EXECUTE PROCEDURE ws.notify_messenger_messages()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON ws.messenger_messages');
        $this->addSql('DROP FUNCTION IF EXISTS ws.notify_messenger_messages()');
        $this->addSql('DROP TABLE ws.messenger_messages');
    }
}
