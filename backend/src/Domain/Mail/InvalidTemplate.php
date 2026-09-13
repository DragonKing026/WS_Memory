<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * A template that cannot be saved, with a message an editor can act on.
 *
 * The messages are Polish and name the allowed places, because the person reading
 * them is looking at a text area and needs to know what to type instead — "invalid
 * placeholder" tells them nothing they did not already suspect.
 */
final class InvalidTemplate extends \DomainException
{
    /**
     * @param list<string> $unknown
     * @param list<string> $allowed
     */
    public static function unknownPlaceholders(array $unknown, array $allowed): self
    {
        return new self(\sprintf(
            'Nieznane miejsca: %s. Dozwolone w tym szablonie: %s.',
            self::listed($unknown),
            self::listed($allowed),
        ));
    }

    /**
     * @param list<string> $sensitive
     */
    public static function sensitiveInSubject(array $sensitive): self
    {
        return new self(\sprintf(
            'W temacie nie wolno używać %s — temat zapisuje się w dzienniku maili, '
            . 'a te miejsca zawierają dane, których dziennik trzymać nie może.',
            self::listed($sensitive),
        ));
    }

    public static function blankSubject(): self
    {
        return new self('Temat nie może być pusty — wiadomość bez tematu trafia do spamu.');
    }

    public static function blankBody(): self
    {
        return new self('Treść nie może być pusta.');
    }

    public static function tooLongSubject(int $limit): self
    {
        return new self(\sprintf('Temat może mieć najwyżej %d znaków.', $limit));
    }

    /**
     * Raised while rendering, not while saving.
     *
     * Reachable only when the closed list shrank after a template was saved — a code
     * change, not an editing mistake. It is deliberately an error rather than a place
     * left as literal text: a mail reading "Cześć, {{ imie }}" is delivered and
     * cannot be taken back, while a refusal lands in the mail journal with this
     * sentence in it and an administrator can fix the template and invite again.
     *
     * @param list<string> $unknown
     */
    public static function cannotRender(array $unknown): self
    {
        return new self(\sprintf(
            'Szablon używa miejsc, których system już nie wypełnia: %s. Popraw treść w panelu.',
            self::listed($unknown),
        ));
    }

    /**
     * @param list<string> $names
     */
    private static function listed(array $names): string
    {
        return implode(', ', array_map(static fn (string $name): string => '{{ ' . $name . ' }}', $names));
    }
}
