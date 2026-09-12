<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Space\SpaceId;

/**
 * One piece of content coming back out of memory.
 *
 * The palace answers in its own vocabulary — wings and rooms — and knows nothing
 * about spaces. The space is attached afterwards, by the service, from our own
 * registry: see self::withSpace(). Until that happens `space` is null, and that
 * is meaningful rather than merely unset — a fragment with no space has not been
 * checked against anybody's permissions yet, and the service refuses to hand it
 * out in that state.
 */
final readonly class MemoryFragment
{
    public function __construct(
        public DrawerId $id,
        public PalaceWing $wing,
        public string $room,
        public string $content,
        public ?SpaceId $space = null,
        public ?string $sourceFile = null,
        public ?float $similarity = null,
        public ?float $lexicalScore = null,
        public ?\DateTimeImmutable $filedAt = null,
        public ?string $addedBy = null,
    ) {
    }

    public function withSpace(SpaceId $space): self
    {
        return new self(
            $this->id,
            $this->wing,
            $this->room,
            $this->content,
            $space,
            $this->sourceFile,
            $this->similarity,
            $this->lexicalScore,
            $this->filedAt,
            $this->addedBy,
        );
    }

    /**
     * A one-line label for listings and audit entries.
     *
     * Derived rather than stored, because the palace keeps content verbatim and
     * has no title field. Taking the first non-empty line matches how both
     * humans and the miner write: the subject comes first.
     */
    public function title(): string
    {
        return self::titleOf($this->content);
    }

    public static function titleOf(string $content): string
    {
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim(ltrim($line, "#>-* \t"));
            if ('' !== $line) {
                return mb_substr($line, 0, 200);
            }
        }

        return '(bez tytułu)';
    }
}
