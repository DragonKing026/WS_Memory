<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * One space as the administration screen needs it: what it is, plus how much is in it.
 *
 * The two counters are the reason this type exists rather than the Space entity being
 * handed to the panel. They are not properties of a space — they are answers to
 * questions about two other tables — and an administrator reads them together with the
 * flags: a space with no members and thirty documents is a mistake somebody has to
 * fix, and neither half of that sentence says it alone.
 *
 * `isPrivate` travels with the rest instead of the listing quietly dropping private
 * spaces. An administrator must be able to see that they exist — a private space still
 * holds content, still counts against storage, and still belongs to an account that
 * may be leaving — but the panel shows them differently, so the flag has to be in the
 * payload rather than implied by the slug's prefix.
 */
final readonly class SpaceOverview
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public bool $isPrivate,
        public bool $requiresProposal,
        public string $palaceWing,
        public int $memberCount,
        public int $documentCount,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Every key written explicitly, nullable ones included: the frontend parses this
     * with a strict schema, where a missing key is not a missing value but a screen
     * that does not render.
     *
     * @return array{
     *     slug: string,
     *     name: string,
     *     description: string|null,
     *     isPrivate: bool,
     *     requiresProposal: bool,
     *     palaceWing: string,
     *     memberCount: int,
     *     documentCount: int,
     *     createdAt: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'isPrivate' => $this->isPrivate,
            'requiresProposal' => $this->requiresProposal,
            'palaceWing' => $this->palaceWing,
            'memberCount' => $this->memberCount,
            'documentCount' => $this->documentCount,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
