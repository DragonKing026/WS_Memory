<?php

declare(strict_types=1);

namespace App\Tests\Domain\Document;

use App\Domain\Document\DocumentSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The address of a document.
 *
 * The test that matters is the one asserting a malformed slug is **refused** rather
 * than tidied up: a caller that links to "Umowy Najmu" and gets a document at
 * "umowy-najmu" has a broken link it cannot see.
 */
final class DocumentSlugTest extends TestCase
{
    public function testPlainSlugIsAccepted(): void
    {
        self::assertSame('najem-lokalu', (new DocumentSlug('najem-lokalu'))->value);
    }

    public function testSlashSeparatesFolders(): void
    {
        self::assertSame('umowy/najem-lokalu', (new DocumentSlug('umowy/najem-lokalu'))->value);
    }

    /**
     * @return list<array{string}>
     */
    public static function odrzucane(): array
    {
        return [
            ['Umowy Najmu'],
            ['umowy najmu'],
            ['Najem'],
            ['najem_lokalu'],
            ['najem--lokalu'],
            ['-najem'],
            ['najem-'],
            ['umowy//najem'],
            ['/najem'],
            ['najem/'],
            ['umowy/ najem'],
            ['najem.md'],
            ['../etc/passwd'],
            ['zażółć'],
            [''],
            ['   '],
        ];
    }

    #[DataProvider('odrzucane')]
    public function testMalformedSlugIsRefusedRatherThanCleanedUp(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentSlug($slug);
    }

    public function testTooLongSlugIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentSlug(str_repeat('a', DocumentSlug::MAX_LENGTH + 1));
    }

    public function testSuggestionFromTitleTransliteratesPolishLetters(): void
    {
        // Dropped rather than transliterated, "świadczenie" becomes "wiadczenie" —
        // a word nobody recognises in a URL.
        self::assertSame('swiadczenie-uslugi', DocumentSlug::fromTitle('Świadczenie usługi')->value);
        self::assertSame('zazolc-gesla-jazn', DocumentSlug::fromTitle('Zażółć gęślą jaźń')->value);
    }

    public function testSuggestionCollapsesPunctuationAndTrims(): void
    {
        self::assertSame('umowa-najmu-2026', DocumentSlug::fromTitle('  Umowa najmu (2026)!  ')->value);
    }

    public function testTitleWithNothingUsableIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentSlug::fromTitle('!!! ???');
    }
}
