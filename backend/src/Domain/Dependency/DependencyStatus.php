<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * One dependency as the administration panel reads it.
 *
 * Not to be confused with App\Domain\Health\DependencyStatus, which answers a
 * different question: that one says whether a dependency is responding at all and
 * is polled by Docker every ten seconds. This one says which release is running,
 * which one exists, and whether the difference is worth acting on.
 *
 * It adds two things to the stored record: a human name, because a panel showing
 * `mempalace` is showing an implementation detail, and `updateAvailable`, computed
 * here so the JSON and the domain can never disagree about it.
 *
 * self::toArray() is the wire shape, kept in the domain rather than in the
 * controller on purpose — the difference between `checkProblem: null` and a
 * missing key is the difference between "sprawdzone, nic nowego" and an
 * undefined-index error in the frontend, and a nullable key written out
 * explicitly cannot drift.
 */
final readonly class DependencyStatus
{
    public function __construct(
        public string $label,
        public DependencyRecord $record,
    ) {
    }

    public function name(): string
    {
        return $this->record->name;
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
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->record->name,
            'label' => $this->label,
            'installed' => self::text($this->record->installed),
            'pinned' => self::text($this->record->pinned),
            'latest' => self::text($this->record->latest),
            'updateAvailable' => $this->record->updateAvailable(),
            // ATOM rather than a formatted date: the browser turns an offset
            // into the reader's local time, and a date formatted server-side
            // would be in the server's timezone with nothing saying so.
            'checkedAt' => $this->record->checkedAt?->format(\DateTimeInterface::ATOM),
            'checkProblem' => $this->record->checkProblem,
        ];
    }

    private static function text(?Version $version): ?string
    {
        return null === $version ? null : (string) $version;
    }
}
