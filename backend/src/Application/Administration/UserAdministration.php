<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Identity\AdministrationRefused;
use App\Domain\Identity\UserRoster;
use App\Entity\AgentToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The two things an administrator may change about somebody else's account:
 * whether they administer the installation, and whether they can sign in at all.
 *
 * Both widen or narrow somebody's reach, so both are audited with the identity of
 * whoever did it (D-016). The audit entry is written before the flush, in the same
 * transaction as the change, which is what makes "the role was granted but nobody
 * knows by whom" impossible rather than merely unlikely.
 *
 * Neither method checks that the caller is an administrator. That check belongs at
 * the edge, where a refusal can be a 403, and duplicating it here would mean two
 * places to keep in step and a service that cannot be reused by the console.
 */
final readonly class UserAdministration
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRoster $roster,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Grants or takes away the global administrator role.
     *
     * The order of the two refusals is deliberate and not obvious. When the target
     * is the only active administrator, both rules apply — it is also the caller,
     * because nobody else could have reached this code — and LastAdministrator is
     * the sentence that names the real problem: not "ask a colleague", which is
     * what SelfDemotion suggests, because there is no colleague. So the graver rule
     * answers first, and SelfDemotion is left for the case it actually describes:
     * a role taken off yourself while somebody else still holds it.
     *
     * @throws AdministrationRefused
     */
    public function setGlobalAdmin(User $actor, string $userId, bool $admin): User
    {
        $target = $this->require($userId);

        if (!$admin) {
            // Read before the change, so it counts the world as it is. Counting
            // after would include the account being demoted and never refuse.
            if ($target->isGlobalAdmin() && $this->roster->countActiveGlobalAdmins() <= 1) {
                throw AdministrationRefused::lastAdministrator();
            }

            if ($target->getId()->equals($actor->getId())) {
                throw AdministrationRefused::selfDemotion();
            }
        }

        // Idempotent on purpose: two administrators granting the same role, or one
        // double-clicking, is not an error. The audit entry is still written —
        // "tried to grant a role that was already there" is a fact about intent,
        // and suppressing it would leave a gap in the trail at exactly the moment
        // two people were acting on the same account.
        if ($admin) {
            $target->promoteToGlobalAdmin();
        } else {
            $target->demoteFromGlobalAdmin();
        }

        $this->audit->record(
            action: $admin ? 'user.global_role_granted' : 'user.global_role_revoked',
            actor: self::actorOf($actor),
            target: ['user' => $target->getEmail()],
        );

        $this->entityManager->flush();

        return $target;
    }

    /**
     * Switches an account on or off.
     *
     * Deactivation revokes the account's agent tokens. Without that the account is
     * switched off only in appearance: ActiveAccountChecker does stop an agent
     * whose owner is inactive, but that protection lives entirely in the owner's
     * `is_active` flag, so switching the account back on would silently re-arm
     * every agent that was writing to the knowledge base before — including the
     * ones nobody remembered existed. Revoking writes the moment access ended into
     * the token rows themselves, which is also the only form of it an incident
     * review can read.
     *
     * @throws AdministrationRefused
     */
    public function setActive(User $actor, string $userId, bool $active): User
    {
        $target = $this->require($userId);

        if (!$active && $target->getId()->equals($actor->getId())) {
            // No "last administrator" rule here: the account switching itself off
            // is the only unrecoverable case, and it is this one. Somebody else's
            // account, administrator or not, can always be switched back on by
            // whoever is left.
            throw AdministrationRefused::selfDeactivation();
        }

        $revoked = [];

        if ($active) {
            $target->activate();
        } else {
            $target->deactivate();

            /** @var list<AgentToken> $tokens */
            $tokens = $this->entityManager->getRepository(AgentToken::class)
                ->findBy(['owner' => $target]);

            foreach ($tokens as $token) {
                if ($token->isRevoked()) {
                    continue;
                }

                $token->revoke();
                $revoked[] = $token->getId()->toRfc4122();
            }
        }

        $this->audit->record(
            action: $active ? 'user.activated' : 'user.deactivated',
            actor: self::actorOf($actor),
            // The identifiers, not just how many. "Which agents stopped, and when"
            // is the question an incident review asks, and a count cannot answer it.
            target: ['user' => $target->getEmail(), 'revoked_tokens' => $revoked],
        );

        $this->entityManager->flush();

        return $target;
    }

    /**
     * @throws AdministrationRefused
     */
    private function require(string $userId): User
    {
        // Checked before the lookup: Doctrine raises a conversion error on a value
        // that is not a UUID, and a malformed identifier deserves the same answer
        // as an unknown one rather than a 500.
        if (!Uuid::isValid($userId)) {
            throw AdministrationRefused::unknownUser();
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$user instanceof User) {
            throw AdministrationRefused::unknownUser();
        }

        return $user;
    }

    private static function actorOf(User $user): Actor
    {
        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }
}
