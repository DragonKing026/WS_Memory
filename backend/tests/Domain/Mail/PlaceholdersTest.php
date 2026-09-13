<?php

declare(strict_types=1);

namespace App\Tests\Domain\Mail;

use App\Domain\Mail\InvalidTemplate;
use App\Domain\Mail\Placeholders;
use PHPUnit\Framework\TestCase;

/**
 * Substitution of places, and the things it deliberately refuses to be.
 *
 * The first group of tests is the security claim: an administrator types into a text
 * area whose contents are rendered on the server, so anything resembling a template
 * engine has to come out the other side as characters. Those tests are the reason
 * this class exists instead of Twig with a sandbox.
 *
 * The one about a value containing a placeholder is the subtle one. Written as a loop
 * of str_replace calls — the shape this code naturally wants to be — substitution
 * would rescan its own output, and a value that happened to contain `{{ link }}`
 * would be substituted a second time.
 */
final class PlaceholdersTest extends TestCase
{
    public function testItSubstitutesEveryPlace(): void
    {
        self::assertSame(
            'Cześć Anna, wejdź na https://przyklad/x',
            Placeholders::render(
                'Cześć {{ imie }}, wejdź na {{ link }}',
                ['imie' => 'Anna', 'link' => 'https://przyklad/x'],
            ),
        );
    }

    public function testItAcceptsAPlaceWithoutInnerSpaces(): void
    {
        self::assertSame('Anna', Placeholders::render('{{imie}}', ['imie' => 'Anna']));
    }

    public function testTheSamePlaceUsedTwiceIsSubstitutedTwice(): void
    {
        self::assertSame(
            'Anna i Anna',
            Placeholders::render('{{ imie }} i {{ imie }}', ['imie' => 'Anna']),
        );
    }

    // ------------------------------------------- nie jest to silnik szablonów

    public function testATemplateEngineConstructTravelsThroughLiterally(): void
    {
        $wrogi = '{% if 1 %}nie{% endif %}{{ 7 * 7 }}{# komentarz #}';

        self::assertSame($wrogi, Placeholders::render($wrogi, []));
    }

    public function testCodeTravelsThroughLiterally(): void
    {
        $wrogi = '<?php system("id"); ?> ${zmienna} {{ imie|upper }} {{ obiekt.metoda() }}';

        self::assertSame($wrogi, Placeholders::render($wrogi, ['imie' => 'Anna']));
    }

    /**
     * A value is never rescanned, so it cannot smuggle a place of its own.
     */
    public function testAValueContainingAPlaceIsNotSubstitutedAgain(): void
    {
        self::assertSame(
            '{{ tajne }}',
            Placeholders::render('{{ tresc }}', ['tresc' => '{{ tajne }}', 'tajne' => 'HASŁO']),
        );
    }

    // ------------------------------------------------------ nieznane miejsca

    public function testRenderingRefusesAPlaceItHasNoValueFor(): void
    {
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('{{ nieistniejace }}');

        Placeholders::render('Cześć {{ nieistniejace }}', ['imie' => 'Anna']);
    }

    public function testItListsOnlyPlacesOutsideTheAllowedSet(): void
    {
        self::assertSame(
            ['obce'],
            Placeholders::unknownIn('{{ imie }} {{ obce }} {{ imie }}', ['imie', 'link']),
        );
    }

    public function testAnEmptyTextUsesNothing(): void
    {
        self::assertSame([], Placeholders::usedIn(''));
    }

    /**
     * A name the pattern cannot express is not a place at all.
     *
     * Uppercase, dots and hyphens are outside the pattern on purpose: the set of
     * things it matches is the set the validation can reason about, so a name it
     * cannot express is one nobody can slip past the check either.
     */
    public function testANameOutsideThePatternIsNotAPlace(): void
    {
        self::assertSame([], Placeholders::usedIn('{{ Imie }} {{ a.b }} {{ a-b }} {{ 1a }}'));
    }
}
