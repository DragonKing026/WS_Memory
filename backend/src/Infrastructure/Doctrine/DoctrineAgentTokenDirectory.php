<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Identity\Actor;
use App\Domain\Identity\AgentIdentity;
use App\Domain\Identity\AgentTokenDirectory;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter: agent tokens read and updated through plain DBAL.
 *
 * Not the ORM, and for the reason that applies to everything on this path: it runs
 * on every single MCP request, and the identity map would sit between a revoked
 * token and its effect. The whole promise of these credentials is that revoking
 * one bites at the next call.
 *
 * Notice what the resolving query checks in SQL rather than in PHP: revocation,
 * expiry AND the owner still being active. One statement, so there is no window
 * in which three separate checks could disagree, and no path on which somebody
 * adds a caller that forgets the third.
 */
final readonly class DoctrineAgentTokenDirectory implements AgentTokenDirectory
{
    /** Rate-limit window. Fixed, not sliding: a minute is short enough that the difference does not matter. */
    private const WINDOW = '1 minute';

    public function __construct(private Connection $connection)
    {
    }

    public function resolve(#[\SensitiveParameter] string $plainToken): ?AgentIdentity
    {
        $plainToken = trim($plainToken);
        if ('' === $plainToken) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT t.id, t.label, t.space_scope, t.user_id
                FROM ws.agent_tokens t
                JOIN ws.users u ON u.id = t.user_id
                WHERE t.token_hash = :hash
                  AND t.revoked_at IS NULL
                  AND (t.expires_at IS NULL OR t.expires_at > now())
                  AND u.is_active = true
                SQL,
            ['hash' => hash('sha256', $plainToken)],
        );

        if (false === $row) {
            return null;
        }

        return new AgentIdentity(
            tokenId: (string) $row['id'],
            label: (string) $row['label'],
            actor: Actor::agent(
                ownerUserId: (string) $row['user_id'],
                agentTokenId: (string) $row['id'],
                spaceScope: $this->scopeOf($row['space_scope']),
            ),
        );
    }

    public function noteUsage(string $tokenId, ?string $ip): int
    {
        // One statement for the usage record and the rate-limit counter (D-022).
        // The row has to be written anyway to record when it was last used, so
        // counting costs nothing extra — and being in the database rather than in
        // a cache makes the limit hold across several backend containers.
        // The interval is a constant of this class, spliced in rather than bound:
        // PostgreSQL will not take a parameter inside an interval literal.
        $sql = \sprintf(
            <<<'SQL'
                UPDATE ws.agent_tokens
                SET last_used_at = now(),
                    last_used_ip = :ip,
                    calls_in_window = CASE
                        WHEN window_started_at > now() - interval '%1$s'
                        THEN calls_in_window + 1
                        ELSE 1
                    END,
                    window_started_at = CASE
                        WHEN window_started_at > now() - interval '%1$s'
                        THEN window_started_at
                        ELSE now()
                    END
                WHERE id = :id
                RETURNING calls_in_window
                SQL,
            self::WINDOW,
        );

        $calls = $this->connection->fetchOne($sql, ['id' => $tokenId, 'ip' => $ip]);

        // No row means the token disappeared between authenticating and this
        // write. Counting it as the first call of a window is harmless; the next
        // request will fail to resolve at all.
        return \is_int($calls) || \is_numeric($calls) ? (int) $calls : 1;
    }

    public function ownerOf(string $tokenId): ?string
    {
        if (!Uuid::isValid($tokenId)) {
            return null;
        }

        // No conditions on revocation or expiry: this answers "who wrote it", and
        // that does not change when a credential is retired.
        $owner = $this->connection->fetchOne(
            'SELECT user_id FROM ws.agent_tokens WHERE id = :id',
            ['id' => $tokenId],
        );

        return \is_string($owner) ? $owner : null;
    }

    /**
     * @return list<SpaceId>|null null = everything the owner may see
     */
    private function scopeOf(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = \is_string($raw) ? json_decode($raw, true) : $raw;

        if (!\is_array($decoded)) {
            return null;
        }

        $spaces = [];
        foreach ($decoded as $slug) {
            if (\is_string($slug) && '' !== trim($slug)) {
                $spaces[] = new SpaceId(trim($slug));
            }
        }

        // An empty list stays an empty list rather than becoming null. The two
        // mean opposite things — "nothing" and "everything the owner has" — and a
        // token being wound down is expressed with the first.
        return $spaces;
    }
}
