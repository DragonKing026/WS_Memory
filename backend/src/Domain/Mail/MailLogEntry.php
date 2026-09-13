<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * One line of the mail journal.
 *
 * **No body, and that is the design.** An invitation mail carries a working token, so
 * a journal holding the rendered message would be a second copy of a credential —
 * beside a table that deliberately stores only its hash, in a place people open
 * casually because it is "only logs". What the message said is reconstructed from the
 * template; who it went to, when, and how it ended is here.
 *
 * The subject is here because it is the only part of a message safe to keep: a
 * sensitive place is refused in a subject when the template is saved, which is what
 * makes that sentence true rather than hopeful.
 */
final readonly class MailLogEntry
{
    public function __construct(
        public string $id,
        public string $recipient,
        public string $templateKey,
        public string $subject,
        public MailStatus $status,
        public int $attempts,
        public ?string $failureReason,
        public \DateTimeImmutable $queuedAt,
        public ?\DateTimeImmutable $lastAttemptAt,
        public ?\DateTimeImmutable $sentAt,
    ) {
    }
}
