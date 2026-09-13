<?php

declare(strict_types=1);

namespace App\Application\Mail;

/**
 * "Hand this already-composed message to the mail server."
 *
 * Carries the rendered subject and body, not the template and its values, because
 * composing happens where it can be refused usefully: in the request, where a broken
 * template produces a journal entry an administrator sees immediately rather than a
 * message that fails in a worker four times over the next minute.
 *
 * **This message contains the invitation token**, and that is the cost of not
 * blocking the request. The token cannot be recomposed later — the database keeps
 * only its sha256 — so anything sending it asynchronously has to carry it, and our
 * queue is a Postgres table. Consequences, all deliberate:
 *
 *   - the mail journal still holds **no body**. This message is transient: Messenger
 *     deletes the row once the handler returns;
 *   - a message that fails every retry lands in the `failed` transport and stays
 *     there until somebody removes it, token and all. It is bounded — an invitation
 *     expires after seven days, which makes the token in it useless — but the
 *     retention note in `docs/05-deployment.md` says to clear that queue, and this is
 *     the reason it says so;
 *   - the alternative shapes are worse. Sending in the request means an unreachable
 *     SMTP server breaks issuing an invitation (the thing TODO-017 exists to
 *     prevent), and storing the plain token to re-render later would put a
 *     recoverable credential in a backup, which is what hashing it was for.
 *
 * Recorded as D-038.
 */
final readonly class SendMail
{
    public function __construct(
        public string $mailLogId,
        public string $recipient,
        public string $subject,
        public string $body,
    ) {
    }
}
