<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Audit\AuditLogQuery;
use App\Domain\Audit\AuditFilter;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The audit log, read.
 *
 * **This surface has one verb and will only ever have one.** There is no route here that
 * deletes an entry, edits one, or clears the log, and adding one would not be a feature —
 * it would end the log's usefulness. What it records is what administrators did, so an
 * administrator able to erase it has recorded nothing (D-016). Retention is an operator's
 * job with database access, described in `docs/05-deployment.md`, where old entries are
 * aggregated into statistics rather than mutated. If a future task asks for a "clear log"
 * button, the answer is in this paragraph.
 *
 * Global administrators only. Every entry names a person or an agent and says what they
 * touched and from which address; the log of who read a private space is itself a thing
 * worth protecting.
 *
 * Two query parameters are validated rather than quietly ignored, unlike the memory
 * browser which drops a half-typed date and shows the unfiltered list. That is the right
 * behaviour there and the wrong one here: a filter that silently widens makes an audit
 * screen say "these are all the entries from yesterday" while showing a year of them, and
 * the reader has no way to notice. So a date that is not a date and an actor that is not
 * an identifier are 422 with a sentence.
 */
final readonly class AdminAuditController
{
    private const FORBIDDEN_BODY = ['error' => 'Dziennik audytu wymaga uprawnień administratora.'];

    public function __construct(
        private Security $security,
        private AuditLogQuery $audit,
    ) {
    }

    #[Route('/api/admin/audit', name: 'api_admin_audit', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $actor = self::text($request, 'actor');
        if (null !== $actor && !Uuid::isValid($actor)) {
            return new JsonResponse(
                ['error' => 'Filtr „actor” przyjmuje identyfikator konta albo tokena agenta.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $since = self::date($request, 'since');
            $before = self::date($request, 'before');
        } catch (\Exception) {
            return new JsonResponse(
                ['error' => 'Zakres dat jest nieprawidłowy. Podaj datę w postaci 2026-09-13 albo 2026-09-13T11:00:00+02:00.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Clamping the limit and blanking empty strings happens in the filter, not here,
        // so that every caller of the journal gets the same ceiling.
        $filter = new AuditFilter(
            action: self::text($request, 'action'),
            spaceSlug: self::text($request, 'space'),
            actor: $actor,
            since: $since,
            before: $before,
            limit: $request->query->getInt('limit', AuditFilter::PAGE_SIZE),
            offset: $request->query->getInt('offset'),
        );

        return new JsonResponse($this->audit->page($filter)->toArray());
    }

    private static function text(Request $request, string $name): ?string
    {
        $raw = $request->query->all()[$name] ?? null;
        $value = \is_scalar($raw) ? trim((string) $raw) : '';

        return '' === $value ? null : $value;
    }

    /**
     * @throws \Exception when the value is not a date
     */
    private static function date(Request $request, string $name): ?\DateTimeImmutable
    {
        $raw = self::text($request, $name);

        return null === $raw ? null : new \DateTimeImmutable($raw);
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
