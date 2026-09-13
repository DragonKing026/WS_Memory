<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * One drawer a batch did not publish, and why.
 *
 * Part of the batch rather than a separate log, deliberately (D-014). A skip
 * report kept somewhere else is a report nobody opens; attached to the batch, it
 * is on the same screen as the thing the person came to look at.
 *
 * `detail` says what was wrong without repeating it — see SecretFinding for why
 * that matters: these rows are stored in the same database the filter exists to
 * keep secrets out of.
 */
final readonly class SkippedDrawer
{
    public function __construct(
        public string $sourceDrawerId,
        public SkipReason $reason,
        public string $detail = '',
    ) {
    }

    public static function secret(string $sourceDrawerId, ScanReport $report): self
    {
        return new self($sourceDrawerId, SkipReason::Secret, $report->describe());
    }

    public static function duplicate(string $sourceDrawerId): self
    {
        return new self($sourceDrawerId, SkipReason::Duplicate);
    }

    /**
     * The stored and displayed form.
     *
     * `detail` and `note` are both here and are not redundant. `detail` is the raw
     * fact and is what reading a row back reconstructs from; `note` is the sentence
     * a person sees. Storing only the sentence would mean reparsing Polish prose to
     * get the fact back, and storing only the fact would mean every reader —
     * including the frontend — reimplementing the wording.
     *
     * @return array{drawer: string, reason: string, detail: string, note: string}
     */
    public function toArray(): array
    {
        return [
            'drawer' => $this->sourceDrawerId,
            'reason' => $this->reason->value,
            'detail' => $this->detail,
            'note' => $this->describe(),
        ];
    }

    public function describe(): string
    {
        return '' === $this->detail
            ? $this->reason->label()
            : \sprintf('%s: %s', $this->reason->label(), $this->detail);
    }
}
