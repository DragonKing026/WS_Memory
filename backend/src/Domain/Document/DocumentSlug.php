<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * The stable address of a document inside a space.
 *
 * Constrained rather than free text, because a slug ends up in URLs, in MCP tool
 * arguments and in links between documents. Letting one contain a slash or a space
 * would mean every one of those places needing its own escaping, and the first one
 * to forget produces a link that silently goes nowhere.
 *
 * Normalising is deliberately NOT done here. A slug arriving as "Umowy Najmu"
 * is refused, not quietly turned into "umowy-najmu": the caller would then link to
 * one address and the document would live at another, which is worse than an error
 * it can see. self::fromTitle() exists for when somebody wants a suggestion.
 */
final readonly class DocumentSlug implements \Stringable
{
    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/';

    public const MAX_LENGTH = 160;

    public function __construct(public string $value)
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('Adres dokumentu nie może być pusty.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'Adres dokumentu może mieć najwyżej %d znaków.',
                self::MAX_LENGTH,
            ));
        }

        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(\sprintf(
                'Adres „%s" jest nieprawidłowy. Dozwolone są małe litery bez ogonków, cyfry, '
                . 'łączniki i ukośnik jako separator folderu — na przykład „umowy/najem-lokalu".',
                $value,
            ));
        }
    }

    /**
     * A suggestion derived from a title, for interfaces that offer one.
     *
     * Polish letters are transliterated rather than dropped: "ś" becoming "s"
     * keeps the word readable, while dropping it produces "wiadczenie".
     */
    public static function fromTitle(string $title): self
    {
        $ogonki = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ];

        $slug = str_replace(array_keys($ogonki), array_values($ogonki), mb_strtolower(trim($title)));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ('' === $slug) {
            throw new \InvalidArgumentException('Z tego tytułu nie da się zrobić adresu.');
        }

        return new self(mb_substr($slug, 0, self::MAX_LENGTH));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
