<?php

declare(strict_types=1);

namespace App\Application\Invitation;

use App\Application\Mail\SendInvitationMail;
use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Identity\AdministrationRefused;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues an invitation — the only route to a new account.
 *
 * The token is random and stored hashed. The plain value is returned once and
 * never persisted, so a leaked database yields no usable invitations.
 *
 * Two entry points share this: `ws:user:invite` on the console, which is how the
 * first account of an installation comes into existence, and the administration
 * screen. They must not drift, which is why the three refusals below live here and
 * not in either caller — a second copy of "is there already an invitation for this
 * address" would eventually answer differently from the first.
 *
 * Sending the mail is the last thing that happens, after the flush, and it cannot
 * fail this operation: an invitation exists whether or not the message goes out, and
 * its link is returned to the caller either way. That is what lets this system be
 * installed with no mail server at all — the panel keeps working exactly as it did
 * before it could send anything.
 */
final readonly class IssueInvitation
{
    private const VALID_FOR = '+7 days';
    private const TOKEN_BYTES = 32;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTrail $audit,
        private SendInvitationMail $mail,
    ) {
    }

    /**
     * @throws AdministrationRefused
     */
    public function __invoke(
        string $email,
        bool $grantsGlobalAdmin = false,
        ?User $invitedBy = null,
    ): IssuedInvitation {
        $email = strtolower(trim($email));

        // Checked first, because the two conflict rules below both search by address
        // and an address that is not one would make their answers meaningless. The
        // invitation is delivered to this string — an unsendable one produces a
        // token nobody can ever use and a row nobody will understand later.
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw AdministrationRefused::malformedEmail($email);
        }

        $existing = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        if (null !== $existing) {
            throw AdministrationRefused::accountExists($email);
        }

        if (null !== $this->pendingFor($email)) {
            throw AdministrationRefused::invitationPending($email);
        }

        $plainToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = new \DateTimeImmutable(self::VALID_FOR);

        $invitation = new Invitation(
            email: $email,
            tokenHash: hash('sha256', $plainToken),
            expiresAt: $expiresAt,
            invitedBy: $invitedBy,
            grantsGlobalAdmin: $grantsGlobalAdmin,
        );

        $this->entityManager->persist($invitation);

        $this->audit->record(
            action: 'invitation.issued',
            actor: $invitedBy ? Actor::human($invitedBy->getId()->toRfc4122()) : null,
            target: ['email' => $email, 'grants_global_admin' => $grantsGlobalAdmin],
        );

        $this->entityManager->flush();

        $issued = new IssuedInvitation(
            invitationId: $invitation->getId()->toRfc4122(),
            email: $email,
            plainToken: $plainToken,
            expiresAt: $expiresAt,
        );

        // After the flush, and deliberately last. The invitation exists whatever
        // happens to the mail: queueing cannot throw (QueueMail says why), an
        // unreachable mail server is a worker's problem rather than this request's,
        // and the link is returned to the caller either way. That ordering is the
        // whole reason this system can be installed without an SMTP server at all.
        ($this->mail)($issued, $invitedBy);

        return $issued;
    }

    /**
     * An invitation to this address that somebody could still accept.
     *
     * Filtered in PHP through `isUsable()` rather than expressed as a WHERE clause
     * on `expires_at`, because that method is where "usable" is defined once for
     * every reader of the table — AcceptInvitation asks the same question, and a
     * second definition in SQL here is exactly how the two would come to disagree
     * about an invitation on its last day. The set being filtered is every
     * invitation ever issued to one address, which is a handful.
     */
    private function pendingFor(string $email): ?Invitation
    {
        $candidates = $this->entityManager->getRepository(Invitation::class)
            ->findBy(['email' => $email, 'acceptedAt' => null]);

        foreach ($candidates as $candidate) {
            if ($candidate->isUsable()) {
                return $candidate;
            }
        }

        return null;
    }
}
