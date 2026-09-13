<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Dependency\DependencyCheckService;
use App\Application\Dependency\UpdateRequestService;
use App\Domain\Audit\AuditTrail;
use App\Domain\Dependency\DependencyStatus;
use App\Domain\Dependency\UpdateRefusal;
use App\Domain\Dependency\UpdateRefused;
use App\Domain\Dependency\Version;
use App\Domain\Identity\Actor;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Which version of each dependency runs, what exists, and what has been ordered.
 *
 * Global administrators only. Not because a version number is a secret — it is printed
 * on PyPI — but because this is where updates are ordered, and an order ends up as
 * `pip install mempalace==<version>` on the host. Same refusal in Polish as
 * SpaceAdministrationController, and the same reason: a surface whose read half is open
 * and write half is not invites the mistake of adding the next endpoint to the open
 * half.
 *
 * The three routes differ in a way that is easy to lose. GET reports what we already
 * know; a GET that probed PyPI would make opening the panel as slow and as fragile as
 * the network, and browsers reload a panel far more often than a dependency publishes a
 * release. POST .../check goes and asks. POST .../update writes a row and answers 202 —
 * nothing has happened yet, and the shape says so.
 *
 * Every answer carries `updater` next to the dependency, including the answer to a
 * check. The panel cannot decide what to show without it: an available update plus no
 * agent is a sentence about the agent, not a button, and a screen that learned about
 * the new version without learning the agent's state would offer one.
 */
final readonly class AdminDependencyController
{
    private const FORBIDDEN_BODY = ['error' => 'Podgląd zależności wymaga uprawnień administratora.'];

    private const UNKNOWN_BODY = ['error' => 'Nie znamy takiej zależności.'];

    public function __construct(
        private Security $security,
        private DependencyCheckService $dependencies,
        private UpdateRequestService $updates,
        private AuditTrail $audit,
    ) {
    }

    #[Route('/api/admin/dependencies', name: 'api_admin_dependencies', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'dependencies' => array_map(
                fn (DependencyStatus $status): array => $this->updates->viewOf($status)->toArray(),
                $this->dependencies->all(),
            ),
            'updater' => $this->updates->updaterState()->toArray(),
        ]);
    }

    #[Route(
        '/api/admin/dependencies/{name}/check',
        name: 'api_admin_dependencies_check',
        methods: ['POST'],
    )]
    public function check(string $name): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        if (!$this->dependencies->knows($name)) {
            return new JsonResponse(self::UNKNOWN_BODY, Response::HTTP_NOT_FOUND);
        }

        $status = $this->dependencies->check();

        // Recorded because this endpoint reaches out to the network on request. Left
        // unaudited, a script hammering it would be invisible, and the trail is also
        // what shows that a check ran at all when its answer is a problem.
        $this->audit->record(
            action: 'dependency.checked',
            actor: $this->actor(),
            target: ['name' => $status->name(), 'problem' => $status->record->checkProblem],
        );

        // 200 even when the check failed. The request succeeded — we asked, and the
        // answer is "nie udało się sprawdzić", which is in the body where the panel can
        // show it. A 5xx here would make a PyPI outage look like a broken backend, and
        // the frontend would have no body to explain itself.
        return new JsonResponse($this->answerFor($status));
    }

    #[Route(
        '/api/admin/dependencies/{name}/update',
        name: 'api_admin_dependencies_update',
        methods: ['POST'],
    )]
    public function update(string $name, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if (!$user->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        // Ordered before parsing the body: an unknown dependency is 404 whatever was
        // sent, and answering 422 about a version for something we do not track would
        // send the reader after the wrong problem.
        if (!$this->dependencies->knows($name)) {
            return new JsonResponse(self::UNKNOWN_BODY, Response::HTTP_NOT_FOUND);
        }

        // The payload carries whatever the client sent — a number, an array, nothing at
        // all. Read through all() rather than get(), because get() answers a non-scalar
        // with a 400 from deep inside HttpFoundation, and "podałeś listę zamiast numeru
        // wersji" is a 422 with a sentence in it like every other bad value here.
        $sent = $request->getPayload()->all()['toVersion'] ?? null;
        $raw = \is_scalar($sent) ? trim((string) $sent) : '';

        // Parsed before anything else touches it, so the only thing that reaches the
        // service is a Version; the service then checks its spelling again against the
        // narrower pattern the host accepts.
        $target = Version::tryParse($raw);

        if (null === $target) {
            return new JsonResponse(
                ['error' => \sprintf(
                    'Numer wersji „%s” jest nieprawidłowy. Podaj wersję w postaci X.Y.Z, na przykład 3.9.0.',
                    $raw,
                )],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->updates->request($user, $name, $target);
        } catch (UpdateRefused $refused) {
            return new JsonResponse(
                ['error' => $refused->getMessage()],
                self::statusFor($refused->reason),
            );
        }

        // 202, not 201 and not 200: the order is recorded and nothing has been done
        // with it. The agent on the host picks it up within a minute (D-032), so the
        // body is the panel's whole state — including the fresh `pendingUpdate` — and
        // the screen can start watching it progress without a second request.
        return new JsonResponse(
            $this->answerFor($this->dependencies->status()),
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * One dependency plus the agent's state, the shape both POST routes answer with.
     *
     * @return array{dependency: array<string, mixed>, updater: array<string, mixed>}
     */
    private function answerFor(DependencyStatus $status): array
    {
        return [
            'dependency' => $this->updates->viewOf($status)->toArray(),
            'updater' => $this->updates->updaterState()->toArray(),
        ];
    }

    /**
     * The one place a domain refusal becomes a status code.
     *
     * The distinctions matter to the caller, which is why they are not all 400: 409
     * means try again later, 422 means change what you asked for, 503 means go and look
     * at the host. The panel shows the sentence either way, but a browser, a script and
     * a monitoring probe each read the number.
     */
    private static function statusFor(UpdateRefusal $reason): int
    {
        return match ($reason) {
            UpdateRefusal::UnknownDependency => Response::HTTP_NOT_FOUND,
            UpdateRefusal::MalformedTarget,
            UpdateRefusal::TargetNotInCatalog => Response::HTTP_UNPROCESSABLE_ENTITY,
            UpdateRefusal::AlreadyInFlight => Response::HTTP_CONFLICT,
            UpdateRefusal::UpdaterSilent => Response::HTTP_SERVICE_UNAVAILABLE,
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

    private function actor(): Actor
    {
        $user = $this->currentUser();

        return Actor::human($user->getId()->toRfc4122(), $user->isGlobalAdmin());
    }
}
