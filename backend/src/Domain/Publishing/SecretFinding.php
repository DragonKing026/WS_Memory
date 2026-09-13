<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * One reason a drawer was refused, said out loud without repeating the secret.
 *
 * The finding carries a kind and a line number and deliberately carries no
 * excerpt of what matched. That is not caution for its own sake: this object is
 * serialised into `publish_batches.skipped_reasons`, which lives in the same
 * database as everything else and is shown on a screen. A filter that copies
 * the password it found into its own report has moved the password, not stopped
 * it — and moved it somewhere nobody thinks to look for secrets.
 */
final readonly class SecretFinding
{
    public function __construct(
        public SecretKind $kind,
        /** 1-based, so it matches what an editor shows. Null when the match spans the whole text. */
        public ?int $line = null,
    ) {
    }

    /**
     * For the person whose drawer was refused.
     */
    public function describe(): string
    {
        return null === $this->line
            ? $this->kind->label()
            : \sprintf('%s (wiersz %d)', $this->kind->label(), $this->line);
    }

    /**
     * @return array{kind: string, line: int|null, note: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'line' => $this->line, 'note' => $this->describe()];
    }
}
