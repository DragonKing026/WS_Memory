<?php

declare(strict_types=1);

namespace App\Domain\Search;

/**
 * The piece of text shown under a search result, with the matches marked.
 *
 * Postgres knows exactly which words it matched — it did the tokenising — and
 * throwing that away only to have the frontend guess at it with a substring
 * search would guess wrong: `to_tsvector` folds case, splits on characters no
 * naive matcher knows about, and matches prefixes. The positions come from the
 * database and travel as structure (see SnippetPart).
 *
 * Semantic results have no matched words at all, and that is not a gap to paper
 * over: a vector match is a match of meaning, and underlining arbitrary words
 * that merely happen to be shared would claim a precision the mode does not
 * have. Those snippets are one unmatched part — see self::plain().
 */
final readonly class Snippet implements \Stringable
{
    /** @param list<SnippetPart> $parts */
    private function __construct(public array $parts)
    {
    }

    /** Text with nothing marked. */
    public static function plain(string $text): self
    {
        return new self('' === $text ? [] : [new SnippetPart($text, false)]);
    }

    /**
     * Text as `ts_headline` returned it, with matches wrapped in sentinels.
     *
     * Tolerant of malformed input on purpose: an unopened or unclosed sentinel
     * yields a snippet with less highlighting rather than an exception. A search
     * result page is not worth failing over a marker, and the sentinels come
     * from a `ts_headline` option we control anyway.
     */
    public static function fromMarked(string $marked, string $start, string $end): self
    {
        $parts = [];
        $rest = $marked;

        while ('' !== $rest) {
            $open = mb_strpos($rest, $start);
            if (false === $open) {
                break;
            }

            $close = mb_strpos($rest, $end, $open + mb_strlen($start));
            if (false === $close) {
                break;
            }

            $before = mb_substr($rest, 0, $open);
            if ('' !== $before) {
                $parts[] = new SnippetPart($before, false);
            }

            $hit = mb_substr($rest, $open + mb_strlen($start), $close - $open - mb_strlen($start));
            if ('' !== $hit) {
                $parts[] = new SnippetPart($hit, true);
            }

            $rest = mb_substr($rest, $close + mb_strlen($end));
        }

        if ('' !== $rest) {
            // Whatever is left carries no markers — either the tail after the
            // last match, or the whole string when there were none.
            $parts[] = new SnippetPart(str_replace([$start, $end], '', $rest), false);
        }

        return new self($parts);
    }

    /** The snippet as plain text, markers gone. */
    public function text(): string
    {
        return implode('', array_map(static fn (SnippetPart $p): string => $p->text, $this->parts));
    }

    public function __toString(): string
    {
        return $this->text();
    }
}
