<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dependency;

use App\Domain\Dependency\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The comparison the rest of this feature rests on.
 *
 * The first test is the reason the class exists. `'3.10.0' < '3.9.0'` is true as
 * text, so a panel comparing strings stops offering updates the moment the minor
 * number passes nine — and it does not break, it reports that everything is fine.
 * That failure is invisible from the outside, which is exactly why it is pinned
 * here rather than left to a reviewer noticing it.
 */
final class VersionTest extends TestCase
{
    public function testTenIsNewerThanNineEvenThoughItSortsEarlierAsText(): void
    {
        $ten = Version::parse('3.10.0');
        $nine = Version::parse('3.9.0');

        self::assertTrue($ten->isNewerThan($nine));
        self::assertFalse($nine->isNewerThan($ten));
    }

    public function testComparesComponentByComponentFromTheLeft(): void
    {
        self::assertTrue(Version::parse('4.0.0')->isNewerThan(Version::parse('3.99.99')));
        self::assertTrue(Version::parse('3.9.10')->isNewerThan(Version::parse('3.9.9')));
        self::assertFalse(Version::parse('3.7.0')->isNewerThan(Version::parse('3.9.0')));
    }

    public function testEqualityIsNumericAndIgnoresATrailingZero(): void
    {
        self::assertTrue(Version::parse('3.9.0')->equals(Version::parse('3.9.0')));

        // `3.7` and `3.7.0` name the same release. Treating them as different
        // would report an update available from a package to itself.
        self::assertTrue(Version::parse('3.7')->equals(Version::parse('3.7.0')));
        self::assertFalse(Version::parse('3.7')->isNewerThan(Version::parse('3.7.0')));
        self::assertFalse(Version::parse('3.7.0')->isNewerThan(Version::parse('3.7')));

        self::assertFalse(Version::parse('3.9.0')->equals(Version::parse('3.9.1')));
    }

    public function testKeepsTheSpellingItWasGiven(): void
    {
        // A number read from the palace has to travel back out unchanged; a
        // normalised "3.7.0.0" in the panel would look like a different release.
        self::assertSame('3.7', (string) Version::parse('3.7'));
        self::assertSame('3.7.0', (string) Version::parse(' 3.7.0 '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rubbish(): iterable
    {
        yield 'empty' => [''];
        yield 'blanks' => ['   '];
        yield 'prose' => ['najnowsza'];
        yield 'a single number' => ['3'];
        yield 'a v prefix' => ['v3.9.0'];
        yield 'trailing dot' => ['3.9.'];
        yield 'five components' => ['1.2.3.4.5'];
        yield 'a range' => ['>=3.9.0'];
        yield 'an sql injection attempt' => ["3.9.0'; DROP TABLE ws.dependency_state --"];
    }

    #[DataProvider('rubbish')]
    public function testRubbishIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Version::parse($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preReleases(): iterable
    {
        yield 'release candidate' => ['3.9.0rc1'];
        yield 'beta' => ['3.9.0b2'];
        yield 'alpha' => ['3.9.0a1'];
        yield 'dev' => ['3.9.0.dev1'];
        yield 'post' => ['3.9.0.post1'];
        yield 'PEP 440 separator' => ['3.9.0-rc.1'];
    }

    #[DataProvider('preReleases')]
    public function testPreReleasesAreRefusedRatherThanRanked(string $value): void
    {
        // The documented choice (see Version's PHPDoc): the only question asked of
        // this type is "should we offer it as an update target", and for anything
        // unstable the answer is no. Refusing to parse makes proposing one
        // impossible instead of making it somebody's job to remember.
        self::assertNull(Version::tryParse($value));

        $this->expectException(\InvalidArgumentException::class);
        Version::parse($value);
    }

    public function testTryParseSiftsAListWithoutTryCatch(): void
    {
        // How PyPiReleaseCatalog walks the release map: stable ones become
        // versions, everything else becomes a null it steps over.
        $parsed = array_filter(array_map(
            static fn (string $release): ?Version => Version::tryParse($release),
            ['3.8.0', '3.9.0rc1', '3.9.0', 'nonsense', '3.10.0'],
        ));

        $spellings = array_values(array_map(static fn (Version $v): string => (string) $v, $parsed));

        self::assertSame(['3.8.0', '3.9.0', '3.10.0'], $spellings);
    }
}
