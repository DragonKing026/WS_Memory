<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who the caller is and what they may reach.
 *
 * This is the frontend's entry point into the permission model — every screen
 * is built from what appears here — so the space list comes from
 * SpaceAccessResolver rather than from a query written for this endpoint.
 * A second way of computing the list is a second way of getting it wrong.
 */
final readonly class MeController
{
    public function __construct(
        private Security $security,
        private SpaceAccessResolver $access,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Nieuwierzytelniony.'], 401);
        }

        $actor = Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());

        $spaces = [];
        foreach ($this->access->allowedSpaces($actor) as $spaceId) {
            $space = $this->entityManager->getRepository(Space::class)
                ->findOneBy(['slug' => $spaceId->value]);
            if (null === $space) {
                continue;
            }

            $spaces[] = [
                'slug' => $space->getSlug(),
                'name' => $space->getName(),
                'role' => $this->access->roleIn($actor, $spaceId)?->value,
                'isPrivate' => $space->isPrivate(),
                'requiresProposal' => $space->requiresProposal(),
            ];
        }

        return new JsonResponse([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'isGlobalAdmin' => $user->isGlobalAdmin(),
            'spaces' => $spaces,
        ]);
    }
}
