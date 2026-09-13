<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Domain\Mail\MailLog;
use App\Domain\Mail\MailSender;
use App\Domain\Mail\RenderedMail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Hands one composed message to the transport and records how it went.
 *
 * The attempt is counted **before** the transport is touched. A worker killed
 * mid-send then still leaves a visible attempt; counted afterwards, the number would
 * miss exactly the failures somebody is looking for.
 *
 * The exception is rethrown after the journal is updated, because that is what makes
 * Messenger retry — and the order matters: journal first, so the reason is readable
 * even if this process does not survive the rethrow.
 *
 * Not idempotent in the strict sense: a retry sends again. That is the intent for a
 * mail, where the failure being retried means the server did not accept it. The cost
 * of being wrong is a duplicate invitation mail with the same working link, which is
 * a nuisance; the cost of not retrying is an invitation nobody ever receives.
 */
#[AsMessageHandler]
final readonly class SendMailHandler
{
    public function __construct(
        private MailSender $sender,
        private MailLog $log,
    ) {
    }

    public function __invoke(SendMail $message): void
    {
        $this->log->beginAttempt($message->mailLogId);

        try {
            $this->sender->send(
                $message->recipient,
                new RenderedMail($message->subject, $message->body),
            );
        } catch (\Throwable $failure) {
            $this->log->markFailed($message->mailLogId, $this->readable($failure));

            throw $failure;
        }

        $this->log->markSent($message->mailLogId);
    }

    /**
     * The transport's own sentence, trimmed to something a table cell can hold.
     *
     * Transport failures are often several lines with a stack trace's worth of
     * detail; the journal needs the first part, which is the part that says what the
     * server refused.
     */
    private function readable(\Throwable $failure): string
    {
        $message = trim($failure->getMessage());

        if ('' === $message) {
            // A throwable with no message tells a reader nothing. Its class at least
            // says which layer gave up.
            return $failure::class;
        }

        return mb_substr($message, 0, 500);
    }
}
