<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Space\SpaceRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person's role within one space.
 *
 * The composite primary key is the point: a user cannot hold two roles in the
 * same space, so "which role wins" is a question the database refuses to let
 * anyone ask.
 */
#[ORM\Entity]
#[ORM\Table(name: 'space_members', schema: 'ws')]
class SpaceMember
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: false, onDelete: 'CASCADE')]
    private Space $space;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SpaceRole::class)]
    private SpaceRole $role;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'added_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    public function __construct(Space $space, User $user, SpaceRole $role, ?User $addedBy = null)
    {
        $this->space = $space;
        $this->user = $user;
        $this->role = $role;
        $this->addedBy = $addedBy;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getSpace(): Space
    {
        return $this->space;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): SpaceRole
    {
        return $this->role;
    }

    public function changeRole(SpaceRole $role): void
    {
        $this->role = $role;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }
}
