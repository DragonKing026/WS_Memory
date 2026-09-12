<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Space\SpaceId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A space: the unit knowledge is divided by — project, client, department.
 *
 * Maps onto a wing in the palace. Sensitive spaces may additionally get their
 * own pgvector namespace, which means separate tables rather than a filter in a
 * query — two levels of isolation for two levels of consequence.
 */
#[ORM\Entity]
#[ORM\Table(name: 'spaces', schema: 'ws')]
class Space
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'palace_wing', length: 150)]
    private string $palaceWing;

    /**
     * Non-null means this space gets its own pgvector namespace, i.e. separate
     * tables. Reserved for spaces where a mistake in the query filter would be
     * unacceptable rather than merely embarrassing.
     */
    #[ORM\Column(name: 'palace_namespace', length: 150, nullable: true)]
    private ?string $palaceNamespace = null;

    #[ORM\Column(name: 'is_private', options: ['default' => false])]
    private bool $isPrivate = false;

    #[ORM\Column(name: 'requires_proposal', options: ['default' => false])]
    private bool $requiresProposal = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $name, ?string $palaceWing = null)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->palaceWing = $palaceWing ?? $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * The private space every user gets on accepting an invitation.
     *
     * It has to exist from the first moment, because a write that names no
     * space lands here (inviolable rule 6) — and an agent's very first write
     * may arrive before anybody has created anything by hand.
     */
    public static function privateFor(User $user): self
    {
        $slug = SpaceId::privateFor($user->getId()->toRfc4122());

        $space = new self($slug->value, 'Prywatna przestrzeń: ' . $user->getDisplayName());
        $space->isPrivate = true;

        return $space;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getPalaceWing(): string
    {
        return $this->palaceWing;
    }

    public function getPalaceNamespace(): ?string
    {
        return $this->palaceNamespace;
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function requiresProposal(): bool
    {
        return $this->requiresProposal;
    }

    public function setRequiresProposal(bool $requires): void
    {
        $this->requiresProposal = $requires;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
