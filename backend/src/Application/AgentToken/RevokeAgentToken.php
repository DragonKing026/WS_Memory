<?php

declare(strict_types=1);

namespace App\Application\AgentToken;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Entity\AgentToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Revokes a token — immediately, and only your own.
 *
 * "Only your own" includes global administrators. Somebody who can silently
 * retire another person's agent can stop their work without a word, and the way
 * to do it visibly is to deactivate the account, which is recorded (D-016).
 *
 * A token belonging to somebody else answers exactly like one that does not
 * exist. Distinguishing them would let anyone enumerate other people's
 * credentials by identifier.
 */
final readonly class RevokeAgentToken
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTrail $audit,
    ) {
    }

    public function __invoke(User $owner, string $tokenId): bool
    {
        // Checked before the lookup: Doctrine would raise a conversion error on
        // a value that is not a UUID, and a malformed identifier deserves the same
        // answer as an unknown one rather than a 500.
        if (!Uuid::isValid($tokenId)) {
            return false;
        }

        $token = $this->entityManager->getRepository(AgentToken::class)->find($tokenId);

        // equals(), not !==: two Uuid objects holding the same value are distinct
        // instances, so identity comparison would refuse the owner their own token
        // as soon as anything stopped sharing Doctrine's identity map.
        if (!$token instanceof AgentToken || !$token->getOwner()->getId()->equals($owner->getId())) {
            return false;
        }

        // Idempotent: revoking twice is not an error, and the entity keeps the
        // first moment so the audit record of when access ended stays true.
        $alreadyRevoked = $token->isRevoked();
        $token->revoke();

        $this->audit->record(
            action: 'agent_token.revoked',
            actor: Actor::human($owner->getId()->toRfc4122(), $owner->isGlobalAdmin()),
            target: [
                'token_id' => $tokenId,
                'label' => $token->getLabel(),
                'already_revoked' => $alreadyRevoked,
            ],
        );

        $this->entityManager->flush();

        return true;
    }
}
