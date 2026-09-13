<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Administration\UserAdministration;
use App\Domain\Identity\AdministrationRefused;
use App\Domain\Identity\UserRoster;
use App\Domain\Identity\UserSummary;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who has an account here, and what they may do with it.
 *
 * Global administrators only — the listing as much as the two changes. The listing
 * is not merely metadata: it is a roll of everyone in the company who has access,
 * with the addresses to reach them and the moment each last signed in, and a read
 * half left open next to a guarded write half is how the next endpoint ends up on
 * the open one by accident.
 *
 * Both POST routes answer with the account in the same shape the listing uses, read
 * back through the roster after the change. That costs one extra query and buys a
 * screen that never has to guess what it just did — including the two numbers
 * (`spaceCount`, `tokenCount`) it cannot derive, one of which deactivation changes.
 *
 * The Polish route segments are deliberate. They are read by people in a URL bar
 * and in a browser's network tab, and they name what the request does in the
 * language the interface is written in; the code around them stays English.
 */
final readonly class AdminUserController
{
    /**
     * Fifty is what fits on a screen; two hundred is the ceiling.
     *
     * Not decoration. The account list grows for the life of an installation —
     * accounts are never deleted, only deactivated — and an unbounded listing is
     * the mistake the document listing already made: it went down on memory before
     * it went down on time, and it went down for everybody, not just for whoever
     * asked for everything.
     */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    private const FORBIDDEN_BODY = ['error' => 'Zarządzanie kontami wymaga uprawnień administratora.'];

    public function __construct(
        private Security $security,
        private UserRoster $roster,
        private UserAdministration $administration,
    ) {
    }

    #[Route('/api/admin/users', name: 'api_admin_users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $limit = max(1, min($request->query->getInt('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $offset = max(0, $request->query->getInt('offset'));

        $query = trim((string) $request->query->get('q', ''));
        $users = $this->roster->page('' === $query ? null : $query, $limit, $offset);

        return new JsonResponse([
            'users' => array_map(self::present(...), $users),
            // The size of THIS page, not of the installation — the same meaning the
            // document and memory listings give it, so a client that already reads
            // one of them reads this one correctly. `hasMore` is what says to ask
            // again, and it costs no second query.
            'count' => \count($users),
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => \count($users) === $limit,
        ]);
    }

    #[Route(
        '/api/admin/users/{id}/rola-globalna',
        name: 'api_admin_users_global_role',
        methods: ['POST'],
    )]
    public function globalRole(string $id, Request $request): JsonResponse
    {
        return $this->change(
            $id,
            $request,
            'admin',
            fn (User $actor, bool $value): User => $this->administration->setGlobalAdmin($actor, $id, $value),
        );
    }

    #[Route(
        '/api/admin/users/{id}/aktywnosc',
        name: 'api_admin_users_activity',
        methods: ['POST'],
    )]
    public function activity(string $id, Request $request): JsonResponse
    {
        return $this->change(
            $id,
            $request,
            'active',
            fn (User $actor, bool $value): User => $this->administration->setActive($actor, $id, $value),
        );
    }

    /**
     * Both changes are the same request: check the role, read one boolean, act,
     * answer with the account.
     *
     * Written once because the interesting part is what they have in common — a
     * refusal that must come back as a sentence and a code, never as a 500 — and
     * two copies of that would eventually differ in the error body.
     *
     * @param \Closure(User, bool): User $apply
     */
    private function change(string $id, Request $request, string $field, \Closure $apply): JsonResponse
    {
        $actor = $this->currentUser();
        if (!$actor->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        // Read through all() rather than getBoolean(): the payload carries whatever
        // the client sent, and a missing field must not silently become `false`.
        // Turning off somebody's account because a key was misspelled is not a
        // mistake anybody would find by reading the response.
        $sent = $request->getPayload()->all()[$field] ?? null;
        if (!\is_bool($sent)) {
            return new JsonResponse(
                ['error' => \sprintf('Pole „%s” musi być wartością true albo false.', $field)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $changed = $apply($actor, $sent);
        } catch (AdministrationRefused $refused) {
            return new JsonResponse(
                ['error' => $refused->getMessage()],
                AdministrationRefusalStatus::of($refused->reason),
            );
        }

        $summary = $this->roster->one($changed->getId()->toRfc4122());
        if (null === $summary) {
            // The row was just written in this request's transaction, so this cannot
            // happen. Kept as a thrown error rather than a nullable body, because a
            // response shaped `{"user": null}` would be drawn as an empty account.
            throw new \LogicException('Zmienione konto zniknęło między zapisem a odczytem.');
        }

        return new JsonResponse(['user' => self::present($summary)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(UserSummary $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'displayName' => $user->displayName,
            'isGlobalAdmin' => $user->isGlobalAdmin,
            'isActive' => $user->isActive,
            'createdAt' => $user->createdAt->format(\DATE_ATOM),
            // Null means "never signed in", which is the field that tells an
            // administrator an invitation was accepted by somebody who then never
            // came back — worth seeing, and indistinguishable from any default.
            'lastLoginAt' => $user->lastLoginAt?->format(\DATE_ATOM),
            'spaceCount' => $user->spaceCount,
            'tokenCount' => $user->tokenCount,
        ];
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
