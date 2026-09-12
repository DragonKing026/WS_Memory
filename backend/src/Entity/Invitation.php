<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An invitation — the only way an account comes into existence.
 *
 * Open registration has no place in a company knowledge base: whoever holds an
 * account can read whatever their spaces contain.
 *
 * Only the hash of the token is stored. The plain value is shown once, when the
 * invitation is issued; a stolen database therefore yields no usable
 * invitations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invitations', schema: 'ws')]
#[ORM\Index(name: 'idx_invitations_token', columns: ['token_hash'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy = null;

    #[ORM\Column(name: 'grants_global_admin', options: ['default' => false])]
    private bool $grantsGlobalAdmin = false;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?User $invitedBy = null,
        bool $grantsGlobalAdmin = false,
    ) {
        $this->id = Uuid::v7();
        $this->email = strtolower(trim($email));
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->invitedBy = $invitedBy;
        $this->grantsGlobalAdmin = $grantsGlobalAdmin;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function grantsGlobalAdmin(): bool
    {
        return $this->grantsGlobalAdmin;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isAccepted(): bool
    {
        return null !== $this->acceptedAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) > $this->expiresAt;
    }

    /**
     * An invitation is usable once and only while it lasts.
     *
     * Both conditions live here rather than in the controller, so a second
     * entry point cannot forget one of them.
     */
    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isAccepted() && !$this->isExpired($now);
    }

    public function markAccepted(): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
    }
}
