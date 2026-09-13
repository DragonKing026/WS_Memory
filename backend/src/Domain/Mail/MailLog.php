<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Port: the record of what this system tried to send.
 *
 * Declared in the domain for the reason AuditTrail is: a system that sends mail
 * without a trace of what it sent is a system where "the invitation never arrived"
 * has no answer, and that is a rule of this product rather than a convenience.
 *
 * Writing is split into four moments instead of one call because each one is a fact
 * that must survive the process dying immediately after it:
 *
 *   - `queue()` — accepted, nothing attempted yet;
 *   - `refuse()` — never attempted, and here is why. A separate entry point, not a
 *     `queue()` followed by `markFailed()`, because a refusal has no attempts and a
 *     journal claiming one attempt for a mail nothing tried to send would be wrong;
 *   - `beginAttempt()` — counted BEFORE the transport is touched, so a worker killed
 *     mid-send still leaves the attempt visible. Counted after a success, the number
 *     would undercount exactly the failures somebody is looking for;
 *   - `markSent()` / `markFailed()` — how it ended.
 */
interface MailLog
{
    /**
     * @return string the id of the journal row
     */
    public function queue(MailTemplateKey $key, string $recipient, string $subject): string;

    /**
     * A mail that was never attempted, with the reason in plain Polish.
     *
     * @return string the id of the journal row
     */
    public function refuse(MailTemplateKey $key, string $recipient, string $subject, string $reason): string;

    /** Increments the attempt count and records when. Silent about an unknown id. */
    public function beginAttempt(string $id): void;

    public function markSent(string $id): void;

    public function markFailed(string $id, string $reason): void;

    public function page(MailLogFilter $filter): MailLogPage;
}
