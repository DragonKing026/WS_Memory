<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Creating spaces and handing out roles.
 *
 * Granting a role is the only operation here that widens somebody else's reach,
 * which is why every path through it is audited with the granter's identity.
 * A global administrator may grant themselves access — that is deliberate — but
 * never without leaving the trace that makes it reviewable (D-016).
 */
final readonly class SpaceAdministrationController
{
    private const NOT_FOUND_BODY = ['error' => 'Nie znaleziono przestrzeni.'];

    public function __construct(
        private Security $security,
        private SpaceAccessResolver $access,
        private EntityManagerInterface $entityManager,
        private AuditTrail $audit,
    ) {
    }

    #[Route('/api/spaces', name: 'api_spaces_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if (!$user->isGlobalAdmin()) {
            return new JsonResponse(
                ['error' => 'Tworzenie przestrzeni wymaga uprawnień administratora.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $payload = $request->getPayload();
        $slug = trim((string) $payload->get('slug'));
        $name = trim((string) $payload->get('name'));

        if ('' === $slug || '' === $name) {
            return new JsonResponse(
                ['error' => 'Wymagane są slug i nazwa przestrzeni.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (str_starts_with($slug, 'priv_')) {
            // The prefix belongs to private spaces created on invitation
            // acceptance. Letting anyone claim it would make a shared space
            // indistinguishable from somebody's private one.
            return new JsonResponse(
                ['error' => 'Prefiks priv_ jest zarezerwowany dla przestrzeni prywatnych.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (null !== $this->findSpace($slug)) {
            return new JsonResponse(
                ['error' => 'Przestrzeń o tym slugu już istnieje.'],
                Response::HTTP_CONFLICT,
            );
        }

        $space = new Space($slug, $name);
        $space->setDescription($payload->get('description'));

        // The creator becomes its administrator immediately. Otherwise the
        // first action after creating a space would be granting yourself
        // access to it — an audit entry that says nothing.
        $membership = new SpaceMember($space, $user, SpaceRole::Admin, $user);

        $this->entityManager->persist($space);
        $this->entityManager->persist($membership);

        $this->audit->record(
            action: 'space.created',
            actor: $this->actor(),
            spaceSlug: $slug,
            target: ['name' => $name],
        );

        $this->entityManager->flush();

        return new JsonResponse(['slug' => $slug, 'name' => $name, 'role' => 'admin'], 201);
    }

    #[Route('/api/spaces/{slug}/members', name: 'api_spaces_add_member', methods: ['POST'])]
    public function addMember(string $slug, Request $request): JsonResponse
    {
        $actor = $this->actor();
        $spaceId = new SpaceId($slug);
        $space = $this->findSpace($slug);

        // A global administrator may administer any space; anyone else needs
        // the admin role in that particular one. Note the order: someone with
        // no access at all gets 404, so a refusal never confirms existence.
        $isGlobalAdmin = $this->currentUser()->isGlobalAdmin();
        $canSee = $isGlobalAdmin || $this->access->canRead($actor, $spaceId);

        if (null === $space || !$canSee) {
            return new JsonResponse(self::NOT_FOUND_BODY, Response::HTTP_NOT_FOUND);
        }

        if ($space->isPrivate()) {
            // A private space that can be shared is not private. There is no
            // role, global or otherwise, that unlocks this.
            return new JsonResponse(
                ['error' => 'Przestrzeni prywatnej nie da się z nikim dzielić.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (!$isGlobalAdmin && !$this->access->canAdminister($actor, $spaceId)) {
            return new JsonResponse(
                ['error' => 'Nadawanie ról w tej przestrzeni wymaga roli administratora.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $role = SpaceRole::tryFrom((string) $request->getPayload()->get('role'));
        if (null === $role) {
            return new JsonResponse(
                ['error' => 'Nieznana rola. Dozwolone: reader, writer, admin.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $member = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => strtolower(trim((string) $request->getPayload()->get('email')))]);
        if (null === $member) {
            return new JsonResponse(
                ['error' => 'Nie ma konta o tym adresie. Najpierw wystaw zaproszenie.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existing = $this->entityManager->getRepository(SpaceMember::class)
            ->findOneBy(['space' => $space, 'user' => $member]);

        if (null !== $existing) {
            $existing->changeRole($role);
        } else {
            $this->entityManager->persist(new SpaceMember($space, $member, $role, $this->currentUser()));
        }

        $this->audit->record(
            action: 'space.member_added',
            actor: $actor,
            spaceSlug: $slug,
            target: ['member' => $member->getEmail(), 'role' => $role->value],
        );

        $this->entityManager->flush();

        return new JsonResponse(['space' => $slug, 'member' => $member->getEmail(), 'role' => $role->value]);
    }

    private function findSpace(string $slug): ?Space
    {
        return $this->entityManager->getRepository(Space::class)->findOneBy(['slug' => $slug]);
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return $user;
    }

    private function actor(): Actor
    {
        $user = $this->currentUser();

        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }
}
