<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * One order to update one dependency — exactly one row of `ws.dependency_updates`.
 *
 * This is the message the web application leaves for the host (D-032). Everything
 * on it is either what an administrator asked for or what the agent reported back;
 * nothing here is derived, because a derived field written down beside the facts is
 * a second truth that goes stale the moment one of the two is updated alone.
 *
 * `$requestedBy` is a raw user id, not a name, for the reason AuthorDirectory
 * explains: a name copied at write time goes stale, and the panel resolves ids to
 * display names in bulk at read time.
 *
 * `$fromVersion` is what the application BELIEVED was running when the order was
 * placed, and it is nullable because MCP `initialize` can be unreachable. It is
 * deliberately not what the agent rolls back to — the agent rolls back to the
 * version in `.env`, because that is the one the system actually starts from. The
 * gap between the two is information, and the agent reports it as a warning.
 */
final readonly class UpdateRequest
{
    /**
     * How long a claimed order may stay silent before we give up on it.
     *
     * A rebuild of the palace image plus a health wait plus the semantic test is
     * minutes, not tens of minutes, so 45 is generous. It has to be generous AND it
     * has to exist: an agent killed mid-run leaves a `running` row, the partial
     * unique index counts that row as in flight, and every future order for that
     * dependency is then refused with a 409 — forever, with no way to clear it from
     * the panel. The timeout is what keeps a dead agent from becoming a permanent
     * outage of this feature.
     */
    public const ABANDONED_AFTER_SECONDS = 45 * 60;

    public function __construct(
        public string $id,
        public string $name,
        public ?Version $fromVersion,
        public Version $toVersion,
        public UpdateStatus $status,
        public string $requestedBy,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $finishedAt = null,
        public ?string $log = null,
    ) {
    }

    /**
     * The wire shape, with the orderer's identity spelled out for a person.
     *
     * The display name is passed in rather than looked up here because resolving it
     * is a query, and a history list resolving one name per row is how a screen
     * becomes slow without anybody being able to point at the slow part.
     *
     * A null name means the account is gone — which the schema forbids today
     * (`ON DELETE RESTRICT`), so this is the branch that keeps a future relaxation
     * of that rule from making the panel unopenable. The order is still shown:
     * history that quietly drops entries is worse than history naming nobody.
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     fromVersion: string|null,
     *     toVersion: string,
     *     requestedBy: string,
     *     requestedAt: string,
     *     finishedAt: string|null,
     *     log: string|null,
     * }
     */
    public function toArray(?string $requestedByName): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'fromVersion' => null === $this->fromVersion ? null : (string) $this->fromVersion,
            'toVersion' => (string) $this->toVersion,
            'requestedBy' => $requestedByName ?? 'nieznane konto',
            // ATOM everywhere, like DependencyStatus: the browser turns an offset
            // into the reader's local time, and a date formatted server-side would
            // be in the server's timezone with nothing saying so.
            'requestedAt' => $this->requestedAt->format(\DateTimeInterface::ATOM),
            'finishedAt' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
            'log' => $this->log,
        ];
    }
}
