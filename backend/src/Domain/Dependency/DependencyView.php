<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * One dependency as the panel needs it: the version situation plus what has been
 * ordered about it.
 *
 * Separate from DependencyStatus, which stays the answer to "where does this
 * dependency stand" and knows nothing about orders — the scheduled check builds one
 * of those every six hours and has no business loading update history to do it. This
 * type is the composition, built only where a screen is being answered.
 *
 * The field the frontend calls `pendingUpdate` is the LATEST order, including a
 * finished one, and that is deliberate rather than sloppy naming on this side of the
 * wire. The log of a failed update is the only trace of a failed semantic test, and a
 * failed semantic test is silent (D-003) — so the most recent order has to stay on
 * screen after it ends, or the one thing worth reading would vanish at the moment it
 * became worth reading.
 *
 * self::toArray() writes every key explicitly, nullable ones included. The frontend
 * parses this with a strict schema: a missing key is not a missing value there, it is
 * a screen that does not render.
 */
final readonly class DependencyView
{
    /**
     * How many earlier orders the panel shows.
     *
     * Five, because the question history answers is "has this been going wrong
     * lately", and the answer is in the last few attempts. An unbounded list would
     * grow a JSON payload with logs in it — each one the full output of a rebuild —
     * on a screen nobody opens to read a year of them.
     */
    public const HISTORY_LIMIT = 5;

    /**
     * @param list<UpdateRequest>   $history the orders before $latest, newest first
     * @param array<string, string> $names   display names by user id, resolved in bulk
     */
    public function __construct(
        public DependencyStatus $status,
        public ?UpdateRequest $latest = null,
        public array $history = [],
        private array $names = [],
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     label: string,
     *     installed: string|null,
     *     pinned: string|null,
     *     latest: string|null,
     *     updateAvailable: bool,
     *     checkedAt: string|null,
     *     checkProblem: string|null,
     *     pendingUpdate: array<string, mixed>|null,
     *     history: list<array<string, mixed>>,
     * }
     */
    public function toArray(): array
    {
        return $this->status->toArray() + [
            'pendingUpdate' => null === $this->latest ? null : $this->requestToArray($this->latest),
            'history' => array_map(
                fn (UpdateRequest $request): array => $this->requestToArray($request),
                $this->history,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestToArray(UpdateRequest $request): array
    {
        return $request->toArray($this->names[$request->requestedBy] ?? null);
    }
}
