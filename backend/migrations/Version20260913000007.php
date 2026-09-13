<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Outgoing mail: the templates a human edits, and the journal of what was sent.
 *
 * The most important thing about `ws.mail_log` is a column it does not have.
 * **There is no body.** An invitation mail contains a working token, and a
 * journal holding the rendered message would be a second copy of that
 * credential — sitting beside a table that deliberately keeps only its sha256,
 * in a place people open casually because "it's only logs". So the journal
 * records who it went to, which template, the subject, the state and the reason
 * it failed; the text is reconstructed from the template when anybody needs to
 * know what it said.
 *
 * `template_key` is a plain string here, with no foreign key to
 * `mail_templates`. The journal is history: it must stay readable and correct
 * after somebody edits the template, and after a future version stops using
 * that template at all. A key nobody recognises any more is a better journal
 * entry than a row that could not be written.
 *
 * **Neither table has a foreign key to `ws.users`**, and that is not laziness.
 *
 * An address is kept instead of an id. It survives the account being deleted, which
 * `ON DELETE SET NULL` would not: "last changed by ktos@web-systems.pl" is what a
 * reader of the editing screen wants, and the alternative loses that fact precisely
 * when somebody is trying to work out who changed the wording. The authority on who
 * did what is the audit journal either way.
 *
 * There is a second, sharper reason. `TRUNCATE ws.users CASCADE` — which every
 * integration test in this repository runs in `setUp` — truncates every table holding
 * a foreign key into `users`. With one here, the templates seeded by this migration
 * would be silently wiped by the first test to run, every subsequent mail in the suite
 * would become "no such template", and nothing would say why. A table carrying
 * installation data rather than user data must therefore stay out of that graph.
 *
 * Three constraints carry rules the application would otherwise have to
 * remember:
 *
 *   - a template with a blank subject or body cannot exist. A mail with no
 *     subject is not a feature request, it is a message that lands in spam, and
 *     an empty body is a mail nobody can act on;
 *   - `sent_at` is set exactly when the status is `sent`. The log answers "did
 *     it go out, and when" — a status that could disagree with its own timestamp
 *     answers it wrongly;
 *   - a failed row must say why. A state of `failed` with no reason sends an
 *     administrator to the container logs, which is precisely what this table
 *     exists to avoid.
 *
 * `failure_reason` and `last_attempt_at` survive a later success on purpose,
 * and the constraint above permits it: "went out on the third attempt, after
 * the server had refused twice" is the useful sentence, and clearing the reason
 * would leave only "sent" with no trace of the trouble.
 */
final class Version20260913000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Maile: szablony edytowane przez administratora i dziennik wysyłki';
    }

    public function up(Schema $schema): void
    {
        // ------------------------------------------------------ mail_templates

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.mail_templates (
                id UUID NOT NULL,
                template_key VARCHAR(60) NOT NULL,
                subject VARCHAR(300) NOT NULL,
                body TEXT NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_by_email VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_mail_templates_key ON ws.mail_templates (template_key)');

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.mail_templates
                ADD CONSTRAINT chk_mail_templates_filled
                CHECK (btrim(subject) <> '' AND btrim(body) <> '')
            SQL);

        $this->addSql("COMMENT ON COLUMN ws.mail_templates.updated_at IS '(DC2Type:datetime_immutable)'");

        // ------------------------------------------------------------ mail_log

        $this->addSql(<<<'SQL'
            CREATE TABLE ws.mail_log (
                id UUID NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                template_key VARCHAR(60) NOT NULL,
                subject VARCHAR(300) NOT NULL,
                status VARCHAR(20) NOT NULL,
                attempts INT DEFAULT 0 NOT NULL,
                failure_reason TEXT DEFAULT NULL,
                queued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE ws.mail_log
                ADD CONSTRAINT chk_mail_log_status
                CHECK (status IN ('queued', 'sent', 'failed'))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.mail_log
                ADD CONSTRAINT chk_mail_log_sent_at
                CHECK ((status = 'sent') = (sent_at IS NOT NULL))
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ws.mail_log
                ADD CONSTRAINT chk_mail_log_failure_reason
                CHECK (status <> 'failed' OR failure_reason IS NOT NULL)
            SQL);

        // The journal is read newest-first, which is the only ordering the screen
        // offers, so the index is built that way rather than left to a sort.
        $this->addSql('CREATE INDEX idx_mail_log_queued_at ON ws.mail_log (queued_at DESC)');
        // The two filters the screen has. Status is low-cardinality, but the
        // interesting query — "show me the failures" — selects the rare value,
        // which is exactly the case where the index earns its keep.
        $this->addSql('CREATE INDEX idx_mail_log_status ON ws.mail_log (status, queued_at DESC)');
        $this->addSql('CREATE INDEX idx_mail_log_recipient ON ws.mail_log (recipient, queued_at DESC)');

        // ---------------------------------------------------- default templates

        // Seeded here so that a fresh installation can send an invitation before
        // anybody has opened the administration panel. A template table that
        // starts empty means the first invitation of every new instance goes
        // nowhere, and the person setting it up has no reason to suspect why.
        //
        // The placeholders used below must match MailTemplateKey::Invitation —
        // the same closed list the editing screen validates against. A test
        // renders every seeded row against its key rather than trusting this
        // file to stay in step with that enum.
        //
        // `{{ link }}` appears in the body and never in the subject: it carries
        // the token, and the subject is copied into `mail_log`.
        $this->addSql(
            <<<'SQL'
                INSERT INTO ws.mail_templates (id, template_key, subject, body, updated_at)
                VALUES (gen_random_uuid(), :key, :subject, :body, NOW())
                SQL,
            [
                'key' => 'invitation',
                'subject' => 'Zaproszenie do bazy wiedzy „{{ instancja }}”',
                // Wcięcie zdejmuje PHP (heredoc elastyczny), więc w bazie treść
                // stoi przy lewej krawędzi — tak, jak ją zobaczy odbiorca.
                'body' => <<<'TEXT'
                    Cześć,

                    {{ zapraszajacy }} zaprasza Cię do wspólnej bazy wiedzy „{{ instancja }}”.

                    Konto zakładasz sam, pod tym adresem:
                    {{ link }}

                    Link jest jednorazowy i wygasa {{ wygasa }}. Po tym terminie poproś o nowe
                    zaproszenie — starego nie da się odnowić.

                    Zaproszenie wystawiono na adres {{ adres_email }}. Jeśli nie wiesz, o co chodzi,
                    zignoruj tę wiadomość: bez otwarcia linku nic się nie stanie.
                    TEXT,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ws.mail_log');
        $this->addSql('DROP TABLE ws.mail_templates');
    }
}
