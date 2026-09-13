<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Domain\Mail\MailSender;
use App\Domain\Mail\RenderedMail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Adapter: hands a message to the configured transport.
 *
 * **Plain text only, with no HTML alternative, and that is a decision rather than an
 * omission.** The body is written by an administrator in a text area and then has
 * values substituted into it. In an HTML mail those values would have to be escaped,
 * every place would need to know whether it lands in text or in an attribute, and a
 * name containing an apostrophe or a `<` would either break the markup or be
 * silently mangled. Plain text has none of that, and an invitation is four sentences
 * and a link.
 *
 * A transport failure is translated into a plain RuntimeException carrying the
 * transport's own sentence. The caller — the Messenger handler — writes that sentence
 * into the journal, which is where an administrator reads it, so it has to survive
 * as text rather than as an exception class only a developer would recognise.
 */
final readonly class SymfonyMailSender implements MailSender
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
        private string $fromName,
    ) {
    }

    public function send(string $recipient, RenderedMail $mail): void
    {
        $message = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($recipient)
            ->subject($mail->subject)
            ->text($mail->body);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $failure) {
            // Wrapped, not re-thrown as it is: the journal shows this text to a
            // person, and Messenger decides whether to retry on the strength of an
            // exception being thrown at all, not on its class.
            throw new \RuntimeException($failure->getMessage(), previous: $failure);
        }
    }
}
