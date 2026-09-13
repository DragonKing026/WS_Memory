<?php

declare(strict_types=1);

namespace App\Tests\Domain\Mail;

use App\Domain\Mail\InvalidTemplate;
use App\Domain\Mail\MailTemplate;
use App\Domain\Mail\MailTemplateKey;
use PHPUnit\Framework\TestCase;

/**
 * What may be saved as the wording of a mail.
 *
 * Every refusal here is checked against the **message** as well as the exception,
 * because an editor staring at a text area needs to be told what to type instead. A
 * test asserting only the class would pass with "invalid template" as the text, which
 * is the same as no message at all.
 *
 * The test about a sensitive place in the subject is the one that protects a promise
 * made elsewhere: `ws.mail_log` stores subjects, and "the journal holds no token" is
 * true only for as long as a subject cannot contain `{{ link }}`.
 */
final class MailTemplateTest extends TestCase
{
    public function testItAcceptsWordingUsingOnlyItsOwnPlaces(): void
    {
        $template = $this->template(
            subject: 'Zaproszenie do {{ instancja }}',
            body: 'Cześć, {{ zapraszajacy }} zaprasza Cię: {{ link }} (wygasa {{ wygasa }}, {{ adres_email }}).',
        );

        self::assertSame(MailTemplateKey::Invitation, $template->key);
    }

    public function testItRefusesAnUnknownPlaceAndSaysWhatIsAllowed(): void
    {
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('{{ nieistniejace }}');
        // The message must also name the way out, not just the mistake.
        $this->expectExceptionMessage('{{ link }}');

        $this->template(body: 'Cześć {{ nieistniejace }}');
    }

    public function testItRefusesASensitivePlaceInTheSubject(): void
    {
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('dziennik');

        $this->template(subject: 'Twój link: {{ link }}');
    }

    public function testAnUnknownPlaceInTheSubjectIsAlsoRefused(): void
    {
        $this->expectException(InvalidTemplate::class);

        $this->template(subject: 'Zaproszenie {{ czegos_takiego_nie_ma }}');
    }

    public function testItRefusesABlankSubject(): void
    {
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('spamu');

        $this->template(subject: '   ');
    }

    public function testItRefusesABlankBody(): void
    {
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('Treść');

        $this->template(body: "\n\t ");
    }

    public function testItRefusesASubjectLongerThanTheColumn(): void
    {
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage((string) MailTemplate::SUBJECT_LIMIT);

        $this->template(subject: str_repeat('a', MailTemplate::SUBJECT_LIMIT + 1));
    }

    /**
     * The limit counts characters, not bytes.
     *
     * A subject of Polish text is shorter in characters than in bytes, and a byte-wise
     * check would refuse wording that fits the column perfectly well.
     */
    public function testTheSubjectLimitCountsCharacters(): void
    {
        $template = $this->template(subject: str_repeat('ą', MailTemplate::SUBJECT_LIMIT));

        self::assertSame(MailTemplate::SUBJECT_LIMIT, mb_strlen($template->subject));
    }

    // ------------------------------------------------------------ renderowanie

    public function testASampleRenderFillsEveryPlaceItDeclares(): void
    {
        $rendered = $this->template(
            subject: 'Zaproszenie do {{ instancja }}',
            body: '{{ zapraszajacy }} {{ link }} {{ wygasa }} {{ adres_email }}',
        )->renderSample();

        self::assertStringNotContainsString('{{', $rendered->subject);
        self::assertStringNotContainsString('{{', $rendered->body);
    }

    /**
     * The sample link is not a token anything would accept.
     *
     * A preview built from a real invitation would put a usable credential on the
     * screen of whoever opened the editor, and the test send would mail it to them.
     */
    public function testTheSampleLinkIsNotAUsableToken(): void
    {
        $link = MailTemplateKey::Invitation->sampleValues()['link'];

        self::assertDoesNotMatchRegularExpression('#/zaproszenie/[0-9a-f]{64}$#', $link);
    }

    public function testEveryDeclaredPlaceHasASampleValue(): void
    {
        foreach (MailTemplateKey::cases() as $key) {
            self::assertSame(
                [],
                array_diff($key->placeholders(), array_keys($key->sampleValues())),
                \sprintf('Szablon %s deklaruje miejsce bez wartości przykładowej.', $key->value),
            );
        }
    }

    public function testEverySensitivePlaceIsOneOfTheDeclaredOnes(): void
    {
        foreach (MailTemplateKey::cases() as $key) {
            self::assertSame(
                [],
                array_diff($key->sensitive(), $key->placeholders()),
                \sprintf('Szablon %s chroni miejsce, którego nie deklaruje.', $key->value),
            );
        }
    }

    private function template(
        string $subject = 'Zaproszenie do {{ instancja }}',
        string $body = 'Wejdź na {{ link }}',
    ): MailTemplate {
        return new MailTemplate(
            key: MailTemplateKey::Invitation,
            subject: $subject,
            body: $body,
            updatedAt: new \DateTimeImmutable('2026-09-13 20:00:00'),
        );
    }
}
