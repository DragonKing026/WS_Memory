<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditFilter;
use App\Domain\Audit\AuditJournal;
use App\Domain\Audit\AuditPage;
use Doctrine\DBAL\Connection;

/**
 * Adapter: `ws.audit_log` read as pages. Nothing here writes to it, and nothing here
 * ever will — see the port.
 *
 * Three things in this class are decisions rather than style, and each one was measured
 * on 20 000 rows before it was written (`EXPLAIN (ANALYZE, BUFFERS)`):
 *
 * **The WHERE clause is assembled, not a chain of `(:param IS NULL OR column = :param)`.**
 * The static form reads better and is what DoctrineMemoryBrowser uses, but it leaves the
 * planner deciding with a parameter it may not have folded yet, and the disjunction with
 * `IS NULL` is exactly the shape that loses an index. Assembled, every filter that IS
 * present becomes a plain equality the planner can use, and one that is absent is not in
 * the statement at all.
 *
 * **The actor filter matches BOTH identifier columns.** A person's id and an agent
 * token's id are different things, and the panel showing the names does not know which
 * kind it is holding. Note that filtering by a person's id also returns what their agents
 * did: an agent's entry carries the token AND the owner's id, because an agent always
 * acts under somebody's authority. That is the useful reading of "show me what Artur did".
 * The `OR` costs nothing now that both columns are indexed — before the second index it
 * was a sequential scan of the whole table (340 buffers for one matching row, against 5
 * after; Version20260913000003).
 *
 * **The action list is a loose index scan, not `SELECT DISTINCT`.** `DISTINCT` reads every
 * row — 20 000 rows and 342 buffers to return six values — and this list is built on every
 * page load of a table that grows forever. The recursive form walks `idx_audit_action` from
 * one distinct value to the next, so it costs the number of DISTINCT actions rather than
 * the number of entries: 24 buffers, and it stays 24 when the log reaches a million rows.
 */
final readonly class DoctrineAuditJournal implements AuditJournal
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(AuditFilter $filter): AuditPage
    {
        [$where, $parameters] = self::conditions($filter);

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT id, actor_user_id, actor_agent_token_id, action, space_slug, target, ip, created_at
                FROM ws.audit_log
                {$where}
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            $parameters + ['limit' => $filter->limit, 'offset' => $filter->offset],
        );

        return new AuditPage(
            entries: array_map(self::entryFrom(...), $rows),
            total: (int) $this->connection->fetchOne(
                "SELECT count(*) FROM ws.audit_log {$where}",
                $parameters,
            ),
            actions: $this->actions(),
        );
    }

    /**
     * Every action value present in the log, sorted, without reading the log.
     *
     * The recursive term asks the index for the smallest action greater than the last one
     * found, which is the loose index scan Postgres will not produce on its own from
     * `SELECT DISTINCT`. The trailing NULL — the step that finds nothing above the last
     * value — is what ends the recursion, and it is filtered out at the end.
     *
     * @return list<string>
     */
    private function actions(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                WITH RECURSIVE walked AS (
                    SELECT (SELECT min(action) FROM ws.audit_log) AS action
                    UNION ALL
                    SELECT (SELECT min(a.action) FROM ws.audit_log a WHERE a.action > w.action)
                    FROM walked w
                    WHERE w.action IS NOT NULL
                )
                SELECT action FROM walked WHERE action IS NOT NULL ORDER BY action
                SQL,
        );

        return array_map(strval(...), $rows);
    }

    /**
     * The filter as SQL: the clause, and the parameters it refers to.
     *
     * @return array{string, array<string, string>}
     */
    private static function conditions(AuditFilter $filter): array
    {
        $clauses = [];
        $parameters = [];

        if (null !== $filter->action) {
            $clauses[] = 'action = :action';
            $parameters['action'] = $filter->action;
        }

        if (null !== $filter->spaceSlug) {
            $clauses[] = 'space_slug = :space';
            $parameters['space'] = $filter->spaceSlug;
        }

        if (null !== $filter->actor) {
            $clauses[] = '(actor_user_id = :actor OR actor_agent_token_id = :actor)';
            $parameters['actor'] = $filter->actor;
        }

        // Inclusive at the bottom, exclusive at the top, so that consecutive ranges
        // neither overlap nor drop an entry between them — the only pair of bounds that
        // lets a reader page through a month a day at a time and see each entry once.
        if (null !== $filter->since) {
            $clauses[] = 'created_at >= :since';
            $parameters['since'] = $filter->since->format('Y-m-d H:i:s');
        }

        if (null !== $filter->before) {
            $clauses[] = 'created_at < :before';
            $parameters['before'] = $filter->before->format('Y-m-d H:i:s');
        }

        return [[] === $clauses ? '' : 'WHERE ' . implode(' AND ', $clauses), $parameters];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function entryFrom(array $row): AuditEntry
    {
        return new AuditEntry(
            id: (string) $row['id'],
            action: (string) $row['action'],
            actorUserId: null === $row['actor_user_id'] ? null : (string) $row['actor_user_id'],
            actorAgentTokenId: null === $row['actor_agent_token_id'] ? null : (string) $row['actor_agent_token_id'],
            spaceSlug: null === $row['space_slug'] ? null : (string) $row['space_slug'],
            target: self::targetFrom($row['target'] ?? null),
            ip: null === $row['ip'] ? null : (string) $row['ip'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function targetFrom(mixed $stored): array
    {
        if (!\is_string($stored)) {
            return [];
        }

        $decoded = json_decode($stored, true);

        // An entry whose target cannot be read is still an entry: the action, the actor
        // and the time are the part that matters, and dropping the row over a payload we
        // cannot parse would take a fact out of the audit log to report a formatting
        // problem.
        if (!\is_array($decoded)) {
            return [];
        }

        $target = [];
        foreach ($decoded as $key => $value) {
            // A JSON array would give integer keys. Recorded targets are objects, so this
            // only skips something already malformed.
            if (\is_string($key)) {
                $target[$key] = $value;
            }
        }

        return $target;
    }
}
