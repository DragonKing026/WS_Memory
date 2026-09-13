<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Domain\Mail\MailTemplateKey;
use App\Entity\User;

/**
 * Sends a template to the administrator looking at it, on sample values.
 *
 * **Sample values, never real ones.** A test send built from a live invitation would
 * mail a working token to whoever pressed the button, and the token would then exist
 * in a second inbox for no reason. The sample link is deliberately not a valid token,
 * so the button is safe to press repeatedly.
 *
 * It goes to the administrator's own address rather than to one they type. A form
 * accepting any recipient is a way to send mail from this server to a stranger with
 * text of the sender's choosing, which is the shape of every open relay — and the
 * question being answered here is "does the mail server work and does my wording look
 * right", for which one's own inbox is the correct destination.
 */
final readonly class SendTestMail
{
    public function __construct(private QueueMail $queue)
    {
    }

    /**
     * @return string the id of the mail journal row
     */
    public function __invoke(MailTemplateKey $key, User $to): string
    {
        return ($this->queue)($key, $to->getEmail(), $key->sampleValues());
    }
}
