<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * The difference between two revisions, computed on demand.
 *
 * Computed rather than stored, because revisions hold complete content (see the
 * migration): a stored diff would be a second representation of the same fact, and
 * two representations of one fact eventually disagree.
 *
 * Line-based, using the classic longest-common-subsequence table. Word-level diffs
 * read better for prose and are a different problem — this one answers "which lines
 * changed", which is what a reader deciding whether to trust a revision needs.
 *
 * The table is O(n·m) in memory, so self::MAX_LINES caps it. A document of ten
 * thousand lines is not a document anybody reads; refusing is better than a request
 * that quietly exhausts the container's memory limit.
 */
final readonly class RevisionDiff
{
    public const KEPT = 'kept';
    public const ADDED = 'added';
    public const REMOVED = 'removed';

    /** Above this, comparing is refused rather than attempted. */
    public const MAX_LINES = 5000;

    /**
     * @param list<array{type: string, line: string, from: ?int, to: ?int}> $lines
     */
    private function __construct(
        public array $lines,
        public int $added,
        public int $removed,
    ) {
    }

    public static function between(string $before, string $after): self
    {
        $left = self::split($before);
        $right = self::split($after);

        if (\count($left) > self::MAX_LINES || \count($right) > self::MAX_LINES) {
            throw new \DomainException(\sprintf(
                'Porównanie jest możliwe do %d wierszy; te rewizje mają %d i %d.',
                self::MAX_LINES,
                \count($left),
                \count($right),
            ));
        }

        $lines = self::walk($left, $right, self::lcsTable($left, $right));

        return new self(
            $lines,
            \count(array_filter($lines, static fn (array $l): bool => self::ADDED === $l['type'])),
            \count(array_filter($lines, static fn (array $l): bool => self::REMOVED === $l['type'])),
        );
    }

    public function isIdentical(): bool
    {
        return 0 === $this->added && 0 === $this->removed;
    }

    /**
     * A unified-diff-like rendering, for a console or a plain-text answer.
     */
    public function render(): string
    {
        $marks = [self::KEPT => ' ', self::ADDED => '+', self::REMOVED => '-'];

        return implode("\n", array_map(
            static fn (array $line): string => $marks[$line['type']] . $line['line'],
            $this->lines,
        ));
    }

    /**
     * @return list<string>
     */
    private static function split(string $content): array
    {
        // Any line ending, because content arrives from editors on three systems
        // and a document differing only in line endings must not read as rewritten.
        return preg_split('/\R/u', $content) ?: [];
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     *
     * Indexed by position in both sequences rather than a list: it is written into
     * from the bottom right corner, so "list" would be a promise about insertion
     * order that this loop does not keep.
     *
     * @return array<int, array<int, int>>
     */
    private static function lcsTable(array $left, array $right): array
    {
        $rows = \count($left);
        $columns = \count($right);

        $table = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($i = $rows - 1; $i >= 0; --$i) {
            for ($j = $columns - 1; $j >= 0; --$j) {
                $table[$i][$j] = $left[$i] === $right[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        return $table;
    }

    /**
     * @param list<string>                $left
     * @param list<string>                $right
     * @param array<int, array<int, int>>  $table
     *
     * @return list<array{type: string, line: string, from: ?int, to: ?int}>
     */
    private static function walk(array $left, array $right, array $table): array
    {
        $lines = [];
        $i = 0;
        $j = 0;

        while ($i < \count($left) && $j < \count($right)) {
            if ($left[$i] === $right[$j]) {
                $lines[] = ['type' => self::KEPT, 'line' => $left[$i], 'from' => $i + 1, 'to' => $j + 1];
                ++$i;
                ++$j;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $lines[] = ['type' => self::REMOVED, 'line' => $left[$i], 'from' => $i + 1, 'to' => null];
                ++$i;
            } else {
                $lines[] = ['type' => self::ADDED, 'line' => $right[$j], 'from' => null, 'to' => $j + 1];
                ++$j;
            }
        }

        for (; $i < \count($left); ++$i) {
            $lines[] = ['type' => self::REMOVED, 'line' => $left[$i], 'from' => $i + 1, 'to' => null];
        }

        for (; $j < \count($right); ++$j) {
            $lines[] = ['type' => self::ADDED, 'line' => $right[$j], 'from' => null, 'to' => $j + 1];
        }

        return $lines;
    }
}
