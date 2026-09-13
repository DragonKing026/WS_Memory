<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Application\Invitation\IssuedInvitation;
use App\Domain\Mail\MailTemplateKey;
use App\Entity\User;

/**
 * Turns an issued invitation into the mail that delivers it.
 *
 * A class of its own rather than four lines inside IssueInvitation, because this is
 * where the two things the mail needs and the invitation does not live: the public
 * address the link is built from, and the name the instance calls itself.
 *
 * **Refuses when there is no public address**, and that refusal is the point. The
 * parameter behind it has no fallback — unlike the one the console prints, where
 * `127.0.0.1:8080` is correct for the person reading it. A link built from a
 * localhost address and mailed to somebody else points at their own browser, and they
 * would have no way of knowing that. A journal entry saying why nothing was sent is
 * strictly better than a message whose link cannot work.
 */
final readonly class SendInvitationMail
{
    public function __construct(
        private QueueMail $queue,
        private string $linkBaseUrl,
        private string $instanceName,
    ) {
    }

    /**
     * @return string the id of the mail journal row
     */
    public function __invoke(IssuedInvitation $issued, ?User $invitedBy): string
    {
        if ('' === trim($this->linkBaseUrl)) {
            return $this->queue->refuse(
                MailTemplateKey::Invitation,
                $issued->email,
                'Nie ustawiono publicznego adresu instancji (WS_PUBLIC_URL), '
                . 'więc link w mailu nie prowadziłby nigdzie. Zaproszenie istnieje '
                . '— link można skopiować z panelu.',
            );
        }

        return ($this->queue)(MailTemplateKey::Invitation, $issued->email, [
            'zapraszajacy' => $this->nameOf($invitedBy),
            'instancja' => $this->instanceName,
            'link' => $issued->acceptUrl($this->linkBaseUrl),
            'wygasa' => $this->moment($issued->expiresAt),
            'adres_email' => $issued->email,
        ]);
    }

    /**
     * Who the recipient will read as the sender of the invitation.
     *
     * An invitation issued from the console has no person behind it — that is how the
     * first account of an installation comes into existence — and the mail says so in
     * a way that reads as intended rather than as a missing value.
     */
    private function nameOf(?User $invitedBy): string
    {
        return $invitedBy?->getDisplayName() ?? 'Administrator bazy wiedzy';
    }

    /**
     * The expiry, said plainly, with the timezone named.
     *
     * Timestamps here are the server's, which is not necessarily the recipient's, and
     * "wygasa 20.09.2026, 12:00" without that word invites somebody to arrive an hour
     * late and find a dead link with no explanation.
     */
    private function moment(\DateTimeImmutable $moment): string
    {
        return $moment->format('d.m.Y, H:i') . ' (' . $moment->format('T') . ')';
    }
}
