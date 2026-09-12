<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter: writes audit entries through Doctrine.
 *
 * Takes the IP and user agent from the current request rather than from the
 * caller. Were they parameters, every call site would have to remember them and
 * some would not — and an audit entry without provenance answers only half the
 * question it exists for. Console commands simply have no request, and that
 * absence is itself informative.
 */
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    public function record(
        string $action,
        ?Actor $actor = null,
        ?string $spaceSlug = null,
        array $target = [],
    ): void {
        $request = $this->requestStack->getCurrentRequest();

        $entry = new AuditLog(
            action: $action,
            actorUserId: $actor ? Uuid::fromString($actor->userId) : null,
            actorAgentTokenId: $actor?->agentTokenId !== null
                ? Uuid::fromString($actor->agentTokenId)
                : null,
            spaceSlug: $spaceSlug,
            target: $target,
            ip: $request?->getClientIp(),
            userAgent: $request?->headers->get('User-Agent'),
        );

        $this->entityManager->persist($entry);
    }
}
