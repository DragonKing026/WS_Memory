<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Space\SpaceMemberAdministration;
use App\Domain\Space\MembershipRefusal;
use App\Domain\Space\MembershipRefused;
use App\Domain\Space\SpaceDirectory;
use App\Domain\Space\SpaceMemberView;
use App\Domain\Space\SpaceOverview;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every space in the installation, and who holds which role in each.
 *
 * Global administrators only, and the refusal is the same Polish sentence on all four
 * routes — a surface whose read half is open and write half is not invites the mistake of
 * adding the next endpoint to the open half.
 *
 * This is the `/api/admin` half of space administration and it deliberately does not
 * repeat the other. `POST /api/spaces` and `POST /api/spaces/{slug}/members` live in
 * SpaceAdministrationController, are reachable by a space's own administrator, and answer
 * "create" and "grant". What is here is the installation-wide view, which nobody but a
 * global administrator has any business seeing, plus the two operations that TAKE AWAY —
 * demotion and removal. Those two carry a rule (a space must keep an administrator) and
 * it lives in SpaceMemberAdministration, not in this class.
 *
 * The listing shows private spaces too, with `isPrivate` set. They hold content, count
 * against storage and belong to accounts that may be leaving, so hiding them from an
 * administrator would hide exactly the thing this screen exists to show — but their
 * membership is not editable here, and the 422 that says so is the same whoever asks.
 */
final readonly class AdminSpaceController
{
    private const FORBIDDEN_BODY = ['error' => 'Przegląd przestrzeni wymaga uprawnień administratora.'];

    public function __construct(
        private Security $security,
        private SpaceDirectory $spaces,
        private SpaceMemberAdministration $members,
    ) {
    }

    #[Route('/api/admin/spaces', name: 'api_admin_spaces', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $limit = max(1, min($request->query->getInt('limit', SpaceDirectory::PAGE_SIZE), SpaceDirectory::MAX_PAGE_SIZE));
        $offset = max(0, $request->query->getInt('offset'));

        $spaces = $this->spaces->overview($limit, $offset);
        $total = $this->spaces->total();

        return new JsonResponse([
            'spaces' => array_map(static fn (SpaceOverview $s): array => $s->toArray(), $spaces),
            // The total, not the size of this page: it is printed next to the paging
            // controls. The space listing can afford an exact count where a growing log
            // could not — there are tens of spaces, not millions of rows.
            'count' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $offset + \count($spaces) < $total,
        ]);
    }

    #[Route('/api/admin/spaces/{slug}/members', name: 'api_admin_space_members', methods: ['GET'])]
    public function membersOf(string $slug): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => new JsonResponse([
            'members' => array_map(
                static fn (SpaceMemberView $member): array => $member->toArray(),
                $this->members->members($slug),
            ),
        ]));
    }

    #[Route(
        '/api/admin/spaces/{slug}/members/{userId}',
        name: 'api_admin_space_member_role',
        methods: ['PUT'],
    )]
    public function changeRole(string $slug, string $userId, Request $request): JsonResponse
    {
        return $this->guarded(function () use ($slug, $userId, $request): JsonResponse {
            // Read through all() rather than get(): get() answers a non-scalar with a 400
            // from deep inside HttpFoundation, and "podałeś listę zamiast nazwy roli" is a
            // 422 with a sentence in it, like every other bad value on this surface.
            $sent = $request->getPayload()->all()['role'] ?? null;

            return new JsonResponse($this->members->changeRole(
                $this->currentUser(),
                $slug,
                $userId,
                \is_scalar($sent) ? trim((string) $sent) : '',
            )->toArray());
        });
    }

    #[Route(
        '/api/admin/spaces/{slug}/members/{userId}',
        name: 'api_admin_space_member_remove',
        methods: ['DELETE'],
    )]
    public function removeMember(string $slug, string $userId): JsonResponse
    {
        return $this->guarded(function () use ($slug, $userId): JsonResponse {
            $this->members->remove($this->currentUser(), $slug, $userId);

            // 204: the membership is gone and there is nothing left to say about it. The
            // panel reloads the list, which is the only state it could show anyway.
            return new JsonResponse(status: Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * The access rule and the refusal mapping, once for the three routes that need both.
     *
     * @param callable(): JsonResponse $route
     */
    private function guarded(callable $route): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        try {
            return $route();
        } catch (MembershipRefused $refused) {
            return new JsonResponse(
                ['error' => $refused->getMessage()],
                self::statusFor($refused->reason),
            );
        }
    }

    /**
     * The one place a domain refusal becomes a status code.
     *
     * The numbers matter to the caller beyond the sentence: 404 means the thing is not
     * there, 409 means the system will not be left in that state, 422 means the request
     * cannot be carried out on this kind of space no matter who asks.
     */
    private static function statusFor(MembershipRefusal $reason): int
    {
        return match ($reason) {
            MembershipRefusal::UnknownSpace,
            MembershipRefusal::UnknownMember => Response::HTTP_NOT_FOUND,
            MembershipRefusal::MalformedRole,
            MembershipRefusal::PrivateSpace => Response::HTTP_UNPROCESSABLE_ENTITY,
            MembershipRefusal::LastAdministrator => Response::HTTP_CONFLICT,
        };
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return $user;
    }
}
