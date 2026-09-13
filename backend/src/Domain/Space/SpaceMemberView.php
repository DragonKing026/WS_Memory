<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * One membership as a person reads it: who, with what role, since when, granted by whom.
 *
 * `addedBy` is a display name and not an identifier, because the question it answers
 * is "who let this person in" and a UUID answers that with "no idea". It is nullable
 * for two different reasons that the panel treats the same way: a membership created
 * before anybody recorded the granter (a space seeded by migration or by the console),
 * and one whose granter's account has since been removed — `ws.space_members.added_by`
 * is `ON DELETE SET NULL` precisely so that losing an account never takes a membership
 * with it.
 */
final readonly class SpaceMemberView
{
    public function __construct(
        public string $userId,
        public string $displayName,
        public string $email,
        public SpaceRole $role,
        public \DateTimeImmutable $addedAt,
        public ?string $addedBy,
    ) {
    }

    /**
     * @return array{
     *     userId: string,
     *     displayName: string,
     *     email: string,
     *     role: string,
     *     addedAt: string,
     *     addedBy: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'displayName' => $this->displayName,
            'email' => $this->email,
            'role' => $this->role->value,
            'addedAt' => $this->addedAt->format(\DATE_ATOM),
            'addedBy' => $this->addedBy,
        ];
    }
}
