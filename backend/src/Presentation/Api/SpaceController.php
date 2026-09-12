<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading spaces.
 *
 * One rule governs this whole controller: a space outside your permissions
 * answers exactly like a space that does not exist — same status, same body,
 * byte for byte. "You have no access to the HR space" would confirm that an HR
 * space exists and is worth asking about; the absence of that confirmation is
 * the protection (inviolable rule 3, security rule 7).
 */
final readonly class SpaceController
{
    /** The single answer for "not yours" and "not there". */
    private const NOT_FOUND_BODY = ['error' => 'Nie znaleziono przestrzeni.'];

    public function __construct(
        private Security $security,
        private SpaceAccessResolver $access,
        private EntityManagerInterface $entityManager,
        private AuditTrail $audit,
    ) {
    }

    #[Route('/api/spaces', name: 'api_spaces_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $actor = $this->actor();

        $spaces = [];
        foreach ($this->access->allowedSpaces($actor) as $spaceId) {
            $space = $this->findSpace($spaceId->value);
            if (null !== $space) {
                $spaces[] = $this->present($space, $actor);
            }
        }

        return new JsonResponse(['spaces' => $spaces]);
    }

    #[Route('/api/spaces/{slug}', name: 'api_spaces_get', methods: ['GET'])]
    public function get(string $slug): JsonResponse
    {
        $actor = $this->actor();
        $spaceId = new SpaceId($slug);

        // Permission first, existence second. Checking the other way round
        // would make the response time differ between "absent" and "forbidden"
        // — a slower answer is a disclosure too.
        if (!$this->access->canRead($actor, $spaceId)) {
            return $this->notFound();
        }

        $space = $this->findSpace($slug);
        if (null === $space) {
            return $this->notFound();
        }

        $this->audit->record(action: 'space.read', actor: $actor, spaceSlug: $slug);
        $this->entityManager->flush();

        return new JsonResponse($this->present($space, $actor));
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(self::NOT_FOUND_BODY, Response::HTTP_NOT_FOUND);
    }

    private function findSpace(string $slug): ?Space
    {
        return $this->entityManager->getRepository(Space::class)->findOneBy(['slug' => $slug]);
    }

    /** @return array<string, mixed> */
    private function present(Space $space, Actor $actor): array
    {
        return [
            'slug' => $space->getSlug(),
            'name' => $space->getName(),
            'description' => $space->getDescription(),
            'role' => $this->access->roleIn($actor, new SpaceId($space->getSlug()))?->value,
            'isPrivate' => $space->isPrivate(),
            'requiresProposal' => $space->requiresProposal(),
        ];
    }

    private function actor(): Actor
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }
}
