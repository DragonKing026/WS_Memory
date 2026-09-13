<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\EntryFacts;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\SearchMode;
use App\Domain\Search\SearchHit;
use App\Domain\Search\Snippet;
use App\Domain\Space\SpaceId;

/**
 * Search as a person reads it: one call, two modes, results that say what they are.
 *
 * Permissions are not decided here — that is MemoryService's job, and this class
 * calls it for both modes precisely so there is no second place where "which
 * spaces may this actor read" gets worked out. What this class adds is the part
 * that only matters to a human reader: who wrote the thing, whether anybody
 * checked it, and whether the match is actually any good.
 */
final readonly class SearchService
{
    /**
     * The floor: below this similarity nothing is a good match, whatever else
     * came back.
     *
     * `similarity` is `1 - cosine_distance`, measured against the palace.
     */
    public const WEAK_BELOW = 0.30;

    /**
     * The relative cut: a result this far below the best one is a weak match.
     *
     * An absolute threshold alone does not work, and the measurements say so.
     * Searching five real Polish documents:
     *
     *   "wolne dni"                     → the right document 0.477, the rest 0.31–0.41
     *   "jak rozliczyć hotel"           → the right document 0.575, the rest 0.32–0.39
     *   "nowy pracownik pierwszy dzień" → the right document 0.611, the rest 0.29–0.44
     *
     * The bands overlap: 0.438 is noise in the third query and 0.477 is the
     * answer in the first. No single number separates them, because the scale
     * shifts with how well the query matches anything at all. What is stable is
     * the *gap* — the right answer stands clear of the rest within one query.
     *
     * Hence: strong means "within a quarter of the best score in this answer".
     * Calibrated on five documents, which is few; the two constants are named so
     * that revisiting them on real material is a one-line change rather than an
     * archaeology exercise.
     */
    public const WEAK_RATIO = 0.75;

    /** How much of a drawer to show under a semantic result. */
    private const SNIPPET_LENGTH = 240;

    public function __construct(
        private MemoryService $memory,
        private MemoryRegistry $registry,
    ) {
    }

    /**
     * @param list<SpaceId>|null $inSpaces narrows the set; never widens it
     *
     * @return list<SearchHit>
     */
    public function search(
        Actor $actor,
        MemoryQuery $query,
        SearchMode $mode = SearchMode::Semantic,
        ?array $inSpaces = null,
    ): array {
        return match ($mode) {
            SearchMode::Lexical => $this->memory->searchLexically($actor, $query, $inSpaces),
            SearchMode::Semantic => $this->semantic($actor, $query, $inSpaces),
        };
    }

    /**
     * @param list<SpaceId>|null $inSpaces
     *
     * @return list<SearchHit>
     */
    private function semantic(Actor $actor, MemoryQuery $query, ?array $inSpaces): array
    {
        $fragments = $this->memory->search($actor, $query, $inSpaces);
        if ([] === $fragments) {
            return [];
        }

        // One lookup for the whole page rather than one per result: the same
        // query run N times is how a search screen becomes slow without anybody
        // being able to point at the slow part.
        $facts = $this->registry->describe(array_map(
            static fn (MemoryFragment $f): DrawerId => $f->id,
            $fragments,
        ));

        // The cut depends on the whole answer, so it is computed before any hit
        // is built — SearchHit is readonly, and a "mark it afterwards" pass would
        // mean making it mutable for the sake of one flag.
        $best = 0.0;
        foreach ($fragments as $fragment) {
            $best = max($best, $fragment->similarity ?? 0.0);
        }
        $cut = max(self::WEAK_BELOW, $best * self::WEAK_RATIO);

        $hits = [];
        foreach ($fragments as $fragment) {
            if (null === $fragment->space) {
                // MemoryService refuses to hand out an unattributed fragment, so
                // this cannot happen — and if it ever does, dropping it is the
                // safe direction: the space is what a reader judges a result by.
                continue;
            }

            // A drawer the registry does not know is described from the fragment
            // itself rather than left half-null: every branch below would need the
            // same fallbacks, and spread across four of them they would drift.
            $facts[$fragment->id->value] ??= new EntryFacts(
                kind: MemoryKind::Note,
                title: $fragment->title(),
                byAi: false,
                verified: false,
            );

            $hits[] = $this->hitFrom($fragment, $fragment->space, $facts[$fragment->id->value], $cut);
        }

        return $hits;
    }

    private function hitFrom(MemoryFragment $fragment, SpaceId $space, EntryFacts $facts, float $cut): SearchHit
    {
        $similarity = $fragment->similarity;

        return new SearchHit(
            space: $space,
            kind: $facts->kind,
            title: $facts->title,
            snippet: Snippet::plain(self::excerpt($fragment->content)),
            mode: SearchMode::Semantic,
            score: $similarity,
            // An unknown score counts as weak rather than strong: presenting an
            // unranked result among good ones claims a confidence we do not have.
            weak: null === $similarity || $similarity < $cut,
            byAi: $facts->byAi,
            verified: $facts->verified,
            drawer: $fragment->id,
            documentSlug: $facts->documentSlug,
            at: $fragment->filedAt ?? $facts->filedAt,
        );
    }

    /**
     * The opening of a drawer, cut on a word boundary.
     *
     * Cutting mid-word looks like a rendering bug and costs a reader a moment of
     * doubt about whether the content itself is broken.
     */
    private static function excerpt(string $content): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

        if (mb_strlen($flat) <= self::SNIPPET_LENGTH) {
            return $flat;
        }

        $cut = mb_substr($flat, 0, self::SNIPPET_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');

        // A single word longer than the whole snippet: cut it anyway rather than
        // return the empty string.
        if (false !== $lastSpace && $lastSpace > self::SNIPPET_LENGTH / 2) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return $cut . '…';
    }
}
