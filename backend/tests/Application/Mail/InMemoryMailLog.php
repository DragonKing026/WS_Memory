<?php

declare(strict_types=1);

namespace App\Tests\Application\Mail;

use App\Domain\Mail\MailLog;
use App\Domain\Mail\MailLogEntry;
use App\Domain\Mail\MailLogFilter;
use App\Domain\Mail\MailLogPage;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailTemplateKey;

/**
 * The mail journal without a database.
 *
 * Records the **order** of what happened as well as the final state, because the
 * order is the interesting part: the attempt has to be counted before the transport
 * is touched, and the failure has to be written before the exception is rethrown. A
 * double that only kept the last state would let both of those regress silently.
 */
final class InMemoryMailLog implements MailLog
{
    /** @var array<string, array{key: MailTemplateKey, recipient: string, subject: string, status: MailStatus, attempts: int, reason: ?string}> */
    public array $rows = [];

    /** @var list<string> */
    public array $calls = [];

    private int $next = 0;

    public function queue(MailTemplateKey $key, string $recipient, string $subject): string
    {
        return $this->insert($key, $recipient, $subject, MailStatus::Queued, null, 'queue');
    }

    public function refuse(MailTemplateKey $key, string $recipient, string $subject, string $reason): string
    {
        return $this->insert($key, $recipient, $subject, MailStatus::Failed, $reason, 'refuse');
    }

    public function beginAttempt(string $id): void
    {
        $this->calls[] = 'beginAttempt';
        $row = $this->row($id);
        ++$row['attempts'];
        $this->rows[$id] = $row;
    }

    public function markSent(string $id): void
    {
        $this->calls[] = 'markSent';
        $row = $this->row($id);
        $row['status'] = MailStatus::Sent;
        $this->rows[$id] = $row;
    }

    public function markFailed(string $id, string $reason): void
    {
        $this->calls[] = 'markFailed';
        $row = $this->row($id);
        $row['status'] = MailStatus::Failed;
        $row['reason'] = $reason;
        $this->rows[$id] = $row;
    }

    /**
     * The row, or a loud failure.
     *
     * The real adapter is silent about an unknown id — a worker holding a message for
     * a row somebody deleted has nothing useful to do about it. Here the opposite is
     * right: an id this double has never seen means the test is wrong, and writing a
     * half-built row would let it go on passing.
     *
     * @return array{key: MailTemplateKey, recipient: string, subject: string, status: MailStatus, attempts: int, reason: ?string}
     */
    private function row(string $id): array
    {
        return $this->rows[$id] ?? throw new \LogicException(\sprintf('Dziennik nie zna wiersza %s.', $id));
    }

    public function page(MailLogFilter $filter): MailLogPage
    {
        $entries = [];
        foreach ($this->rows as $id => $row) {
            $entries[] = new MailLogEntry(
                id: $id,
                recipient: $row['recipient'],
                templateKey: $row['key']->value,
                subject: $row['subject'],
                status: $row['status'],
                attempts: $row['attempts'],
                failureReason: $row['reason'],
                queuedAt: new \DateTimeImmutable(),
                lastAttemptAt: null,
                sentAt: null,
            );
        }

        return new MailLogPage($entries, \count($entries));
    }

    private function insert(
        MailTemplateKey $key,
        string $recipient,
        string $subject,
        MailStatus $status,
        ?string $reason,
        string $call,
    ): string {
        $this->calls[] = $call;
        $id = 'mail-' . ++$this->next;

        $this->rows[$id] = [
            'key' => $key,
            'recipient' => $recipient,
            'subject' => $subject,
            'status' => $status,
            'attempts' => 0,
            'reason' => $reason,
        ];

        return $id;
    }
}
