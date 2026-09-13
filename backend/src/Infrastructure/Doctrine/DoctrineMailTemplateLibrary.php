<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Mail\MailTemplate;
use App\Domain\Mail\MailTemplateKey;
use App\Domain\Mail\MailTemplateLibrary;
use App\Domain\Mail\MissingMailTemplate;
use Doctrine\DBAL\Connection;

/**
 * Adapter: the template table on plain DBAL.
 *
 * Not an ORM entity, for the reason the other adapters here give: four columns read
 * by key, written whole, never traversed as a graph. An entity would add an identity
 * map between an administrator pressing save and the next mail going out.
 *
 * `all()` walks the enum rather than the table, so the panel lists the mails this
 * version can send — not the rows that happen to be present. A row for a key the
 * code no longer knows is invisible here, which is correct: nothing renders it. And
 * a key with no row raises MissingMailTemplate instead of being quietly skipped,
 * because the listing is the one place where "the seeding migration did not run" can
 * still be noticed before somebody tries to send.
 *
 * Who last edited is stored as an **address**, not as a reference to an account. See
 * the migration for the two reasons; the one that bites is that a foreign key into
 * `ws.users` would put this table inside the blast radius of the `TRUNCATE ... CASCADE`
 * every integration test runs.
 */
final readonly class DoctrineMailTemplateLibrary implements MailTemplateLibrary
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(MailTemplateKey $key): MailTemplate
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT subject, body, updated_at, updated_by_email
                FROM ws.mail_templates
                WHERE template_key = :key
                SQL,
            ['key' => $key->value],
        );

        if (false === $row) {
            throw MissingMailTemplate::forKey($key);
        }

        // The constructor validates, so a row that stopped matching the closed list
        // of places throws HERE — on the way out of the database, while somebody is
        // looking at the editor — rather than on the night an invitation fails.
        return new MailTemplate(
            key: $key,
            subject: (string) $row['subject'],
            body: (string) $row['body'],
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
            updatedByEmail: null !== $row['updated_by_email'] ? (string) $row['updated_by_email'] : null,
        );
    }

    public function all(): array
    {
        return array_map($this->get(...), MailTemplateKey::cases());
    }

    public function save(MailTemplate $template): void
    {
        $changed = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.mail_templates
                SET subject = :subject,
                    body = :body,
                    updated_at = :now,
                    updated_by_email = :email
                WHERE template_key = :key
                SQL,
            [
                'subject' => $template->subject,
                'body' => $template->body,
                'now' => $template->updatedAt->format('Y-m-d H:i:s'),
                'email' => $template->updatedByEmail,
                'key' => $template->key->value,
            ],
        );

        if (0 === $changed) {
            // No INSERT fallback on purpose. A key with no row means the seeding
            // migration did not run, and an UPSERT here would hide that by creating
            // the row from whatever the editor happened to contain — including on an
            // installation whose migrations are half applied.
            throw MissingMailTemplate::forKey($template->key);
        }
    }
}
