<?php

declare(strict_types=1);

namespace App\Tests\Domain\Document;

use App\Domain\Document\RevisionDiff;
use PHPUnit\Framework\TestCase;

/**
 * Comparing two revisions.
 *
 * Worth testing on its own rather than through an endpoint: this is the one piece
 * of real algorithm in the project, and a diff that is subtly wrong is not an error
 * anybody sees — it is a reviewer deciding to trust a change on the strength of a
 * picture that does not match the text.
 */
final class RevisionDiffTest extends TestCase
{
    public function testIdenticalContentShowsNoChange(): void
    {
        $diff = RevisionDiff::between("pierwszy\ndrugi", "pierwszy\ndrugi");

        self::assertTrue($diff->isIdentical());
        self::assertSame(0, $diff->added);
        self::assertSame(0, $diff->removed);
    }

    public function testAddedLineIsMarkedAndCounted(): void
    {
        $diff = RevisionDiff::between("pierwszy\ntrzeci", "pierwszy\ndrugi\ntrzeci");

        self::assertSame(1, $diff->added);
        self::assertSame(0, $diff->removed);
        self::assertSame(" pierwszy\n+drugi\n trzeci", $diff->render());
    }

    public function testRemovedLineIsMarkedAndCounted(): void
    {
        $diff = RevisionDiff::between("pierwszy\ndrugi\ntrzeci", "pierwszy\ntrzeci");

        self::assertSame(0, $diff->added);
        self::assertSame(1, $diff->removed);
        self::assertSame(" pierwszy\n-drugi\n trzeci", $diff->render());
    }

    public function testChangedLineIsOneRemovalAndOneAddition(): void
    {
        // A line-based diff has no notion of "changed": the honest rendering is
        // the old line gone and the new one arrived, which is also what a reader
        // needs in order to see both texts.
        $diff = RevisionDiff::between("stawka 1000 zł", "stawka 1200 zł");

        self::assertSame(1, $diff->added);
        self::assertSame(1, $diff->removed);
    }

    public function testCommonLinesAreKeptRatherThanRewritten(): void
    {
        // The point of the LCS: a change in the middle must not report every
        // following line as rewritten, or a one-line edit looks like a rewrite.
        $before = "a\nb\nc\nd\ne";
        $after = "a\nb\nZMIANA\nd\ne";

        $diff = RevisionDiff::between($before, $after);

        self::assertSame(1, $diff->added);
        self::assertSame(1, $diff->removed);
        self::assertCount(
            4,
            array_filter($diff->lines, static fn (array $l): bool => RevisionDiff::KEPT === $l['type']),
        );
    }

    public function testDifferentLineEndingsAreNotAChange(): void
    {
        // Content arrives from editors on three systems. A document differing only
        // in line endings must not read as rewritten, or every Windows save would
        // look like a full rewrite in the history.
        $diff = RevisionDiff::between("pierwszy\ndrugi", "pierwszy\r\ndrugi");

        self::assertTrue($diff->isIdentical());
    }

    public function testEmptyBeforeMeansEverythingIsNew(): void
    {
        $diff = RevisionDiff::between('', "pierwszy\ndrugi");

        self::assertSame(2, $diff->added);
        self::assertSame(1, $diff->removed, 'pusta treść to jeden pusty wiersz, który znika');
    }

    public function testLineNumbersPointIntoBothRevisions(): void
    {
        $diff = RevisionDiff::between("a\nb", "a\nc");

        $removed = array_values(array_filter($diff->lines, static fn (array $l): bool => RevisionDiff::REMOVED === $l['type']));
        $added = array_values(array_filter($diff->lines, static fn (array $l): bool => RevisionDiff::ADDED === $l['type']));

        self::assertSame(2, $removed[0]['from']);
        self::assertNull($removed[0]['to']);
        self::assertSame(2, $added[0]['to']);
        self::assertNull($added[0]['from']);
    }

    public function testComparingAbsurdlyLongContentIsRefused(): void
    {
        // The LCS table is O(n·m). Refusing beats a request that quietly eats the
        // container's memory limit and takes the whole process with it.
        $long = implode("\n", array_fill(0, RevisionDiff::MAX_LINES + 1, 'wiersz'));

        $this->expectException(\DomainException::class);
        RevisionDiff::between($long, 'krótka treść');
    }
}
