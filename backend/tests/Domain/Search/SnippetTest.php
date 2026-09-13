<?php

declare(strict_types=1);

namespace App\Tests\Domain\Search;

use App\Domain\Search\Snippet;
use App\Domain\Search\SnippetPart;
use PHPUnit\Framework\TestCase;

/**
 * Turning `ts_headline` output into structure.
 *
 * The point of these tests is that no markup ever reaches the client. Postgres
 * hands back a string with sentinels around the matched words; if that string
 * were passed through and rendered as HTML, a document containing a script tag
 * would run it. So what is asserted here is not only "the right words are
 * marked" but also "the markers are gone".
 */
final class SnippetTest extends TestCase
{
    private const START = "\x02";
    private const END = "\x03";

    public function testMarkedWordsBecomeMatchingParts(): void
    {
        $snippet = Snippet::fromMarked(
            'Wniosek ' . self::START . 'urlopowy' . self::END . ' składa się w systemie',
            self::START,
            self::END,
        );

        self::assertSame(
            [['Wniosek ', false], ['urlopowy', true], [' składa się w systemie', false]],
            self::flatten($snippet),
        );
    }

    public function testTextIsTheSnippetWithoutAnyMarkers(): void
    {
        $snippet = Snippet::fromMarked(
            self::START . 'Urlop' . self::END . ' na żądanie',
            self::START,
            self::END,
        );

        self::assertSame('Urlop na żądanie', $snippet->text());
        self::assertStringNotContainsString(self::START, $snippet->text());
        self::assertStringNotContainsString(self::END, $snippet->text());
    }

    public function testSeveralMatchesInOnePieceOfText(): void
    {
        $snippet = Snippet::fromMarked(
            self::START . 'Urlop' . self::END . ' i ' . self::START . 'urlop' . self::END,
            self::START,
            self::END,
        );

        self::assertSame(
            [['Urlop', true], [' i ', false], ['urlop', true]],
            self::flatten($snippet),
        );
    }

    public function testTextWithNoMatchesIsOneUnmarkedPart(): void
    {
        $snippet = Snippet::fromMarked('nic tu nie pasuje', self::START, self::END);

        self::assertSame([['nic tu nie pasuje', false]], self::flatten($snippet));
    }

    /**
     * A stray opening marker must not swallow the rest of the text, and must not
     * survive into the output either — half-marked text is a rendering glitch,
     * a leaked control character in a page is a bug somebody will chase for an
     * hour.
     */
    public function testAnUnclosedMarkerIsStrippedRatherThanHonoured(): void
    {
        $snippet = Snippet::fromMarked('zaczyna się ' . self::START . 'i nie kończy', self::START, self::END);

        self::assertSame([['zaczyna się i nie kończy', false]], self::flatten($snippet));
        self::assertStringNotContainsString(self::START, $snippet->text());
    }

    public function testMarkupInTheContentIsJustText(): void
    {
        $snippet = Snippet::fromMarked(
            'przykład <script>alert(1)</script> w treści',
            self::START,
            self::END,
        );

        // Carried verbatim as text, with no marked part: the defence is that the
        // client renders parts as text nodes, never as HTML.
        self::assertSame([['przykład <script>alert(1)</script> w treści', false]], self::flatten($snippet));
    }

    public function testPlainTextIsASinglePartAndEmptyTextIsNoParts(): void
    {
        self::assertSame([['sama treść', false]], self::flatten(Snippet::plain('sama treść')));
        self::assertSame([], self::flatten(Snippet::plain('')));
    }

    public function testMarkersAroundPolishCharactersKeepTheirBoundaries(): void
    {
        $snippet = Snippet::fromMarked(
            'przed ' . self::START . 'żółć' . self::END . ' po',
            self::START,
            self::END,
        );

        self::assertSame([['przed ', false], ['żółć', true], [' po', false]], self::flatten($snippet));
    }

    /** @return list<array{string, bool}> */
    private static function flatten(Snippet $snippet): array
    {
        return array_map(
            static fn (SnippetPart $part): array => [$part->text, $part->match],
            $snippet->parts,
        );
    }
}
