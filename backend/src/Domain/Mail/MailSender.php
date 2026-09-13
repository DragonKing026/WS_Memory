<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Port: the thing that actually hands a message to a mail server.
 *
 * A port rather than a direct call to Symfony's mailer, so that the journal and the
 * retry rules can be tested without a transport, and so that a test can make sending
 * fail on demand. "A refused SMTP server does not break issuing an invitation" is a
 * claim about behaviour; without a sender that can be told to refuse, it would be a
 * claim asserted by reading the code.
 */
interface MailSender
{
    /**
     * @throws \RuntimeException when the message could not be handed over
     */
    public function send(string $recipient, RenderedMail $mail): void;
}
