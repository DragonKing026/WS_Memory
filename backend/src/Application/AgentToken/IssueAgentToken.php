<?php

declare(strict_types=1);

namespace App\Application\AgentToken;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Entity\AgentToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues a credential for an AI agent.
 *
 * The secret is random and stored hashed; the plain value is returned once. The
 * `wsm_` prefix is not decoration — it lets secret scanners and humans recognise
 * what they are looking at, which matters for a string that will be pasted into
 * shell commands and configuration files.
 *
 * The requested scope is verified against the owner's **current** permissions and
 * a space they cannot reach is refused outright. That check is not what enforces
 * inviolable rule 4 — SpaceAccessResolver intersects on every request, so an
 * over-reaching scope would grant nothing anyway. It exists so that a mistake is
 * reported now, to a person who can fix it, rather than silently yielding a token
 * that reads nothing and cannot be debugged from the agent's side.
 */
final readonly class IssueAgentToken
{
    private const TOKEN_BYTES = 32;
    private const PREFIX = 'wsm_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpaceAccessResolver $access,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param list<string>|null $spaceScope null = everything the owner may see
     */
    public function __invoke(
        User $owner,
        string $label,
        ?array $spaceScope = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): IssuedAgentToken {
        $label = trim($label);
        if ('' === $label) {
            throw new \DomainException('Token musi mieć etykietę — po czymś trzeba go potem rozpoznać.');
        }

        if (null !== $expiresAt && $expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Data wygaśnięcia jest w przeszłości.');
        }

        $actor = Actor::human($owner->getId()->toRfc4122(), $owner->isGlobalAdmin());

        if (null !== $spaceScope) {
            foreach ($spaceScope as $slug) {
                if (!$this->access->canRead($actor, new SpaceId($slug))) {
                    throw new \DomainException(\sprintf(
                        'Nie masz dostępu do przestrzeni „%s", więc token też go nie dostanie.',
                        $slug,
                    ));
                }
            }
        }

        $plainToken = self::PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));

        $token = new AgentToken(
            owner: $owner,
            label: $label,
            tokenHash: hash('sha256', $plainToken),
            spaceScope: $spaceScope,
            expiresAt: $expiresAt,
        );

        $this->entityManager->persist($token);

        $this->audit->record(
            action: 'agent_token.issued',
            actor: $actor,
            target: [
                'token_id' => $token->getId()->toRfc4122(),
                'label' => $label,
                'scope' => $spaceScope,
                'expires_at' => $expiresAt?->format(\DATE_ATOM),
            ],
        );

        $this->entityManager->flush();

        return new IssuedAgentToken(
            tokenId: $token->getId()->toRfc4122(),
            label: $label,
            plainToken: $plainToken,
            spaceScope: $spaceScope,
            expiresAt: $expiresAt,
        );
    }
}
