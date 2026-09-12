<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter: writes audit entries immediately, through DBAL.
 *
 * It used to persist an AuditLog entity and leave the flush to whoever called it.
 * That worked for as long as every caller happened to flush afterwards — and the
 * MCP gateway does not, because a tool call changes no entities. Every single
 * agent action went unrecorded, and nothing failed to say so. A test asking for
 * the entry found none (TODO-004).
 *
 * So the write happens here and now. The trade-off is named rather than hidden: an
 * entry inside a transaction that later rolls back is still rolled back with it,
 * and an entry written just before an unrelated failure can over-report an attempt.
 * For an append-only audit trail that is the right direction — a log that
 * occasionally records an attempt that did not complete is useful; a log that
 * silently drops entries because nobody flushed is worse than no log, because it
 * reads as proof that nothing happened (D-024).
 *
 * Takes the IP and user agent from the current request rather than from the caller.
 * Were they parameters, every call site would have to remember them and some would
 * not. Console commands have no request, and that absence is itself informative.
 */
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(
        private Connection $connection,
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

        $this->connection->insert('ws.audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'actor_user_id' => $actor?->userId,
            'actor_agent_token_id' => $actor?->agentTokenId,
            'action' => $action,
            'space_slug' => $spaceSlug,
            'target' => json_encode($target, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'ip' => $request?->getClientIp(),
            // Truncated rather than refused: a user agent longer than the column
            // is a curiosity, and losing the whole entry over it would be absurd.
            'user_agent' => null !== $request ? $this->trimmed($request->headers->get('User-Agent')) : null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function trimmed(?string $value): ?string
    {
        return null === $value ? null : mb_substr($value, 0, 255);
    }
}
