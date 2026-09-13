<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Substitution of places, deliberately not a template engine.
 *
 * A template edited in a browser and rendered by a template engine is running
 * somebody else's code on the server. Twig has a sandbox, but a sandbox is a
 * protection that has to be maintained rather than a property of the thing — and
 * this backend has no Twig at all (D-008), which here turns out to be a gift.
 *
 * So: `{{ nazwa }}` from a closed list, and nothing else. No loops, no conditions,
 * no calls, no filters. Anything that looks like an engine construct — `{% if %}`,
 * `${...}`, a PHP tag — does not match the pattern below and therefore travels into
 * the message as the literal characters somebody typed.
 *
 * **One pass, and that matters.** `preg_replace_callback` walks the text once, so a
 * substituted value is never scanned again: an invitation link that happened to
 * contain the characters `{{ link }}` would be left exactly as it is, rather than
 * being substituted a second time. A loop of `str_replace` calls would have that
 * hole, and it is the shape this code most naturally wants to be.
 */
final readonly class Placeholders
{
    /**
     * A place is `{{ name }}` with optional inner spaces.
     *
     * Lowercase letters, digits and underscores only, starting with a letter. Narrow
     * on purpose: the set of things this can match is the set of things the
     * validation below can reason about, so a name it cannot express is a name an
     * administrator cannot smuggle past the check either.
     */
    private const PATTERN = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/u';

    /**
     * Every place used in the text, each one once, in the order they appear.
     *
     * @return list<string>
     */
    public static function usedIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The text with every known place replaced by its value.
     *
     * @param array<string, string> $values
     *
     * @throws InvalidTemplate when the text uses a place the caller has no value for
     */
    public static function render(string $text, array $values): string
    {
        $missing = array_values(array_diff(self::usedIn($text), array_keys($values)));
        if ([] !== $missing) {
            throw InvalidTemplate::cannotRender($missing);
        }

        $rendered = preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => $values[$match[1]],
            $text,
        );

        if (null === $rendered) {
            // Only reachable on a PCRE failure — backtrack limits and the like. Loud,
            // because the alternative is sending the unrendered text with the token
            // placeholder still in it.
            throw new \RuntimeException('Nie udało się podstawić miejsc w szablonie.');
        }

        return $rendered;
    }

    /**
     * Places in the text that are not on the allowed list.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function unknownIn(string $text, array $allowed): array
    {
        return array_values(array_diff(self::usedIn($text), $allowed));
    }
}
