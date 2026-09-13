<?php

declare(strict_types=1);

namespace App\Application\Invitation;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Identity\AdministrationRefused;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Withdraws an invitation that nobody has accepted.
 *
 * The row is deleted rather than marked. The alternative — pushing `expires_at`
 * into the past — would leave the listing showing "wygasłe" for something that was
 * deliberately withdrawn, and those two are not the same fact: one means nobody
 * got round to it, the other means somebody changed their mind. Deleting keeps the
 * listing honest, and the audit entry keeps the history, which is the right split:
 * the trail is where "who withdrew what, and when" belongs (D-016).
 *
 * An expired invitation may be withdrawn too. It grants nothing, so this is only
 * housekeeping — and refusing it would leave a list that can never be tidied.
 *
 * An accepted one may not, and the refusal says why in the one sentence that
 * matters: the account exists now, and deleting a row about how it came to exist
 * would take away nobody's access while destroying the only record of who let them
 * in.
 */
final readonly class RevokeInvitation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @throws AdministrationRefused
     */
    public function __invoke(User $actor, string $invitationId): void
    {
        // Before the lookup: Doctrine raises a conversion error on a value that is
        // not a UUID, and a malformed identifier deserves the same answer as an
        // unknown one rather than a 500.
        if (!Uuid::isValid($invitationId)) {
            throw AdministrationRefused::unknownInvitation();
        }

        $invitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);

        if (!$invitation instanceof Invitation) {
            throw AdministrationRefused::unknownInvitation();
        }

        if ($invitation->isAccepted()) {
            throw AdministrationRefused::invitationAccepted($invitation->getEmail());
        }

        // Recorded before the removal, while the address is still readable from the
        // entity. The entry has to carry it: after the flush there is nothing left
        // in the table to say who this invitation was for.
        $this->audit->record(
            action: 'invitation.revoked',
            actor: Actor::human($actor->getId()->toRfc4122(), $actor->isGlobalAdmin()),
            target: [
                'email' => $invitation->getEmail(),
                'grants_global_admin' => $invitation->grantsGlobalAdmin(),
            ],
        );

        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }
}
