<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Domain\Mail\InvalidTemplate;
use App\Domain\Mail\MailLog;
use App\Domain\Mail\MailTemplateKey;
use App\Domain\Mail\MailTemplateLibrary;
use App\Domain\Mail\MissingMailTemplate;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Composes a mail and puts it in the queue — the one way anything here sends mail.
 *
 * **Never throws.** Every caller is in the middle of doing something that matters
 * more than the mail: issuing an invitation, changing a membership. A mail that
 * cannot be composed or cannot be queued must not take that operation down with it,
 * so a failure becomes a journal row with a reason in it and the caller carries on.
 * The invitation still exists and its link can still be copied from the panel, which
 * is exactly how the system worked before it could send anything at all.
 *
 * Composing happens **here**, in the request, rather than in the worker. A template
 * using a place the code no longer fills is then refused once, visibly, next to the
 * administrator who can fix it — instead of four times in a worker log.
 */
final readonly class QueueMail
{
    public function __construct(
        private MailTemplateLibrary $templates,
        private MailLog $log,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param array<string, string> $values one per place the template may use
     *
     * @return string the id of the journal row, queued or refused
     */
    public function __invoke(MailTemplateKey $key, string $recipient, array $values): string
    {
        try {
            $rendered = $this->templates->get($key)->render($values);
        } catch (InvalidTemplate|MissingMailTemplate $problem) {
            return $this->refuse($key, $recipient, $problem->getMessage());
        }

        $id = $this->log->queue($key, $recipient, $rendered->subject);

        // Dispatch last. A message handed to the bus before the journal row exists
        // would be a worker racing to update a row that is not there yet — and with
        // the doctrine transport the two writes are in the same transaction, so the
        // race is real rather than theoretical.
        $this->bus->dispatch(new SendMail(
            mailLogId: $id,
            recipient: $recipient,
            subject: $rendered->subject,
            body: $rendered->body,
        ));

        return $id;
    }

    /**
     * Records a mail that will not be attempted, with a reason a person can act on.
     *
     * Public because some refusals are known before composing: no public address
     * configured means every link would be dead, and finding that out from a journal
     * entry beats finding it out from a colleague who clicked one.
     *
     * @return string the id of the journal row
     */
    public function refuse(MailTemplateKey $key, string $recipient, string $reason): string
    {
        return $this->log->refuse($key, $recipient, $this->subjectForRefusal($key), $reason);
    }

    /**
     * A readable subject for a row whose message was never composed.
     *
     * Rendered from the sample values, which are safe by construction — a place
     * carrying a credential cannot appear in a subject, because saving such a
     * template is refused. If even that fails, the template's name does the job:
     * a journal entry with no subject at all would be unreadable.
     */
    private function subjectForRefusal(MailTemplateKey $key): string
    {
        try {
            return $this->templates->get($key)->renderSample()->subject;
        } catch (InvalidTemplate|MissingMailTemplate) {
            return $key->label();
        }
    }
}
