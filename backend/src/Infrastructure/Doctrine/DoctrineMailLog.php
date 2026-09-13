<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Mail\MailLog;
use App\Domain\Mail\MailLogEntry;
use App\Domain\Mail\MailLogFilter;
use App\Domain\Mail\MailLogPage;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailTemplateKey;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter: the mail journal on plain DBAL.
 *
 * Writes immediately rather than waiting for somebody to flush, for the reason
 * DoctrineAuditTrail spells out: the callers here include a Messenger worker, and a
 * journal that depends on an unrelated flush is a journal that silently records
 * nothing. The failure mode of writing now — an entry inside a transaction that
 * later rolls back — is the harmless direction.
 *
 * **The rendered message is never a parameter of anything here.** There is no column
 * for it and no method that would accept one. An invitation body carries a working
 * token, and the point of this table is to be freely readable.
 *
 * The ordering carries `id` as a tiebreaker. `queued_at` has second precision, and
 * several mails going out in the same second is the normal case rather than the
 * exotic one — without the tiebreaker the second page could repeat a row the first
 * page already showed, or skip one entirely.
 *
 * `markSent()` clears nothing. A message refused twice and accepted on the third
 * attempt keeps the reason of the last refusal beside its `sent` status, which is
 * what somebody debugging a flaky mail server needs to see. The check constraint
 * permits exactly that combination and forbids the meaningless ones.
 */
final readonly class DoctrineMailLog implements MailLog
{
    /** Matches the VARCHAR(300) column; a subject is truncated rather than lost. */
    private const SUBJECT_LIMIT = 300;

    public function __construct(private Connection $connection)
    {
    }

    public function queue(MailTemplateKey $key, string $recipient, string $subject): string
    {
        return $this->insert($key, $recipient, $subject, MailStatus::Queued, null);
    }

    public function refuse(MailTemplateKey $key, string $recipient, string $subject, string $reason): string
    {
        return $this->insert($key, $recipient, $subject, MailStatus::Failed, $reason);
    }

    public function beginAttempt(string $id): void
    {
        // Back to `queued` on a retry, so a row does not sit at `failed` while a
        // worker is actively trying again. The reason stays: it describes the last
        // attempt, and clearing it would lose the only clue while the retry runs.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.mail_log
                SET attempts = attempts + 1,
                    last_attempt_at = :now,
                    status = CASE WHEN status = 'sent' THEN status ELSE 'queued' END
                WHERE id = :id
                SQL,
            ['now' => $this->stamp(), 'id' => $id],
        );
    }

    public function markSent(string $id): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.mail_log
                SET status = 'sent', sent_at = :now
                WHERE id = :id
                SQL,
            ['now' => $this->stamp(), 'id' => $id],
        );
    }

    public function markFailed(string $id, string $reason): void
    {
        // `sent_at IS NULL` guards the one ordering that would otherwise write a
        // contradiction: a late failure notice for a message the transport already
        // accepted. The database would refuse it anyway — `chk_mail_log_sent_at`
        // ties the status to the timestamp — and a constraint violation here would
        // fail a worker that has nothing left to do.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ws.mail_log
                SET status = 'failed', failure_reason = :reason
                WHERE id = :id AND sent_at IS NULL
                SQL,
            ['reason' => $reason, 'id' => $id],
        );
    }

    public function page(MailLogFilter $filter): MailLogPage
    {
        [$where, $params] = $this->conditions($filter);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ws.mail_log' . $where,
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT id, recipient, template_key, subject, status, attempts,
                       failure_reason, queued_at, last_attempt_at, sent_at
                FROM ws.mail_log{$where}
                ORDER BY queued_at DESC, id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            $params + ['limit' => $filter->limit, 'offset' => $filter->offset],
        );

        return new MailLogPage(
            entries: array_map($this->entryFrom(...), $rows),
            total: $total,
        );
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function conditions(MailLogFilter $filter): array
    {
        $clauses = [];
        $params = [];

        if (null !== $filter->status) {
            $clauses[] = 'status = :status';
            $params['status'] = $filter->status->value;
        }

        if (null !== $filter->recipient) {
            // A fragment, case-insensitively: an administrator hunting a failure
            // types what they remember of the address. The column holds addresses
            // already lowercased by IssueInvitation, so LOWER() on it is belt and
            // braces for rows written by anything that forgets.
            $clauses[] = 'LOWER(recipient) LIKE :recipient';
            $params['recipient'] = '%' . $this->escapedForLike($filter->recipient) . '%';
        }

        return [[] === $clauses ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * `%` and `_` typed into the search box are characters, not wildcards.
     *
     * Without this, searching for `_` matches every address in the table and reads
     * as "the filter does nothing".
     */
    private function escapedForLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function insert(
        MailTemplateKey $key,
        string $recipient,
        string $subject,
        MailStatus $status,
        ?string $reason,
    ): string {
        $id = Uuid::v7()->toRfc4122();

        $this->connection->insert('ws.mail_log', [
            'id' => $id,
            'recipient' => mb_substr($recipient, 0, 255),
            'template_key' => $key->value,
            'subject' => mb_substr($subject, 0, self::SUBJECT_LIMIT),
            'status' => $status->value,
            // A refusal was never attempted, so it stays at zero. A journal claiming
            // one attempt for a mail nothing tried to send would send somebody
            // looking through mail server logs for a connection that never happened.
            'attempts' => 0,
            'failure_reason' => $reason,
            'queued_at' => $this->stamp(),
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entryFrom(array $row): MailLogEntry
    {
        return new MailLogEntry(
            id: (string) $row['id'],
            recipient: (string) $row['recipient'],
            templateKey: (string) $row['template_key'],
            subject: (string) $row['subject'],
            status: MailStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            failureReason: null !== $row['failure_reason'] ? (string) $row['failure_reason'] : null,
            queuedAt: new \DateTimeImmutable((string) $row['queued_at']),
            lastAttemptAt: $this->dateOrNull($row['last_attempt_at']),
            sentAt: $this->dateOrNull($row['sent_at']),
        );
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable((string) $value);
    }

    private function stamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
