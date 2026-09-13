<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * A release number, compared the way a human reads it.
 *
 * Exists because comparing version strings is wrong and the wrongness is quiet:
 * `'3.10.0' < '3.9.0'` holds lexicographically, so a panel built on string
 * comparison stops offering updates exactly when the minor number passes nine —
 * and it does not fail, it simply says everything is fine. Hence numeric
 * comparison, component by component, and a test that pins that one case.
 *
 * **Pre-releases are refused, not ranked.** `3.9.0rc1`, `3.9.0b2`, `3.9.0.dev1`
 * and `3.9.0.post1` all throw from self::parse(). The reason is what this type is
 * for: the only question asked of it is "should we offer this as an update
 * target", and the answer for anything unstable is no, whatever its ordering
 * against a stable release would be. Giving pre-releases a place in the ordering
 * would mean every caller having to remember to exclude them, and the one that
 * forgets proposes an update to a release candidate. A parser that refuses them
 * makes that unrepresentable; a caller sifting a list of releases uses
 * self::tryParse() and skips the nulls.
 *
 * Comparison ignores a trailing zero component: `3.7` and `3.7.0` are the same
 * release, because they are. What self::__toString() gives back is the spelling
 * it was handed, so a value read from the palace round-trips unchanged.
 */
final readonly class Version implements \Stringable
{
    /**
     * Two to four numeric components and nothing else.
     *
     * Two, not three, because `3.7` is a version people and package indexes both
     * write; four because PEP 440 allows `3.7.0.1` and MemPalace is a Python
     * package. A single number is refused: `3` is far more often a typo, a major
     * number somebody meant to finish, or a field that got truncated.
     */
    private const PATTERN = '/^\d{1,6}(?:\.\d{1,6}){1,3}$/';

    /**
     * @param non-empty-list<int> $parts the components, trailing zeros intact
     */
    private function __construct(
        private string $spelling,
        private array $parts,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on anything that is not a stable X.Y.Z
     */
    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Numer wersji nie może być pusty.');
        }

        if (1 !== preg_match(self::PATTERN, $trimmed)) {
            throw new \InvalidArgumentException(\sprintf(
                'Numer wersji „%s" jest nieprawidłowy. Oczekiwano wyłącznie wersji stabilnej '
                . 'w postaci X.Y.Z — wydania przedpremierowe (rc, beta, dev, post) są odrzucane.',
                $trimmed,
            ));
        }

        /** @var non-empty-list<int> $parts the pattern guarantees at least two components */
        $parts = array_map(static fn (string $part): int => (int) $part, explode('.', $trimmed));

        return new self($trimmed, $parts);
    }

    /**
     * The same thing, for callers sifting a list they did not write.
     *
     * PyPI hands back every release a package ever had, pre-releases among them,
     * and a foreach wrapped in try/catch to skip them reads as if the exception
     * were unexpected. It is expected: most of that list is not an update target.
     */
    public static function tryParse(string $value): ?self
    {
        try {
            return self::parse($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function equals(self $other): bool
    {
        return 0 === $this->compareTo($other);
    }

    /**
     * Negative, zero or positive, like every other comparator.
     *
     * The shorter list is padded with zeros rather than declared smaller: `3.7`
     * names the same release as `3.7.0`, and treating it as older would report an
     * update available from a package to itself.
     */
    private function compareTo(self $other): int
    {
        $length = max(\count($this->parts), \count($other->parts));

        for ($i = 0; $i < $length; ++$i) {
            $mine = $this->parts[$i] ?? 0;
            $theirs = $other->parts[$i] ?? 0;

            if ($mine !== $theirs) {
                return $mine <=> $theirs;
            }
        }

        return 0;
    }

    public function __toString(): string
    {
        return $this->spelling;
    }
}
