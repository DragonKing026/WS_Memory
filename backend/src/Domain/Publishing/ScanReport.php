<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * What a scanner found in one drawer.
 *
 * A report rather than a boolean, and the difference matters upstream: the batch
 * has to say WHY it dropped something (D-014 asks for a skip report as part of
 * the batch), and "the filter said no" is not an answer anybody can act on. A
 * person who sees "klucz prywatny (wiersz 12)" knows what to fix in their local
 * palace; one who sees "odrzucone" deletes the wing.
 */
final readonly class ScanReport
{
    /** @param list<SecretFinding> $findings */
    public function __construct(public array $findings = [])
    {
    }

    public static function clean(): self
    {
        return new self();
    }

    public function isClean(): bool
    {
        return [] === $this->findings;
    }

    /**
     * Every reason, in one line, for a log or a screen.
     */
    public function describe(): string
    {
        return implode('; ', array_map(
            static fn (SecretFinding $finding): string => $finding->describe(),
            $this->findings,
        ));
    }

    /**
     * @return list<array{kind: string, line: int|null, note: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (SecretFinding $f): array => $f->toArray(), $this->findings);
    }
}
