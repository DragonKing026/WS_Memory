<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Port: where update orders live between the panel and the host.
 *
 * This is the only channel between the web application and the machine it runs on
 * (D-032), which makes two of the methods below unusual for a repository — they are
 * not storage conveniences, they are the concurrency contract:
 *
 *   - self::add() must fail when another order for the same dependency is in flight,
 *     and must fail because the DATABASE said so. Two administrators clicking at the
 *     same millisecond both pass any check written in PHP;
 *   - self::claimNext() must hand the same order to at most one caller, whatever the
 *     host's timer does about overlapping runs.
 *
 * An implementation that satisfied the signatures without those two properties would
 * pass every test that does one thing at a time, and would queue a second rebuild of
 * the palace image behind the first one in production.
 */
interface UpdateRequestRepository
{
    /**
     * Writes a new order.
     *
     * @throws UpdateRefused with reason AlreadyInFlight when the database's partial
     *                       unique index refuses it — the race that PHP cannot see
     */
    public function add(UpdateRequest $request): void;

    /**
     * The most recent order for this dependency, whatever its state.
     *
     * Also the answer to "is one in flight": a pending or running order is always
     * the newest one for its dependency, because the constraint above makes it
     * impossible to write a later one while it lasts.
     */
    public function latestFor(string $name): ?UpdateRequest;

    /**
     * The newest $limit orders for this dependency, newest first.
     *
     * @return list<UpdateRequest>
     */
    public function recentFor(string $name, int $limit): array;

    /**
     * Takes the oldest pending order and marks it running, atomically.
     *
     * Oldest first because orders are a queue of one in practice, and if two ever
     * exist for different dependencies the one that waited longer should not wait
     * again.
     *
     * @return UpdateRequest|null null when there is nothing to do — the ordinary
     *                            case, since the host's timer ticks every minute
     *                            and updates are rare
     */
    public function claimNext(\DateTimeImmutable $startedAt): ?UpdateRequest;

    /**
     * Records the outcome the agent reported.
     *
     * @return bool false when no order with that id was waiting for a result —
     *              an unknown id, or one already closed by the timeout below
     */
    public function finish(
        string $id,
        UpdateStatus $outcome,
        string $log,
        \DateTimeImmutable $finishedAt,
    ): bool;

    /**
     * Closes orders an agent claimed and never came back from.
     *
     * Called lazily on read rather than from a scheduled job, because the only
     * moment the answer matters is when somebody looks — and a cron entry that has
     * to run for this feature to keep working is one more thing that can be missing
     * on a machine where the agent is already missing.
     *
     * @param string $log what to append to the order's log, so the panel says why it
     *                    ended rather than showing a failure with nothing in it
     *
     * @return int how many were closed
     */
    public function failAbandoned(\DateTimeImmutable $startedBefore, string $log, \DateTimeImmutable $now): int;
}
