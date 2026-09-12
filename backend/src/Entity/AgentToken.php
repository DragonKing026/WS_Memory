<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A machine credential: what an AI agent presents at /mcp.
 *
 * Only the hash is stored. The plain value exists once, in the answer to the
 * request that created it, and nowhere else — a stolen database yields no usable
 * tokens (the same rule as invitations).
 *
 * A token never grants more than its owner has at this moment (inviolable rule
 * 4). That is not enforced here but in SpaceAccessResolver, which intersects the
 * scope with the owner's current memberships on every single request. This class
 * only records what the scope is; revoking the owner's role is enough to revoke
 * it for every one of their agents, with no action on these rows at all.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_tokens', schema: 'ws')]
#[ORM\Index(name: 'idx_agent_tokens_user', columns: ['user_id'])]
class AgentToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    /**
     * Null means "everything the owner may see"; a list narrows it.
     *
     * An empty list is a different thing again — a token that reaches nothing —
     * and it stays expressible on purpose: it is what a token being wound down
     * should look like before anybody deletes it.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'space_scope', type: Types::JSON, nullable: true)]
    private ?array $spaceScope = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'last_used_ip', length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string>|null $spaceScope
     */
    public function __construct(
        User $owner,
        string $label,
        string $tokenHash,
        ?array $spaceScope = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->label = trim($label);
        $this->tokenHash = $tokenHash;
        $this->spaceScope = $spaceScope;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return list<string>|null */
    public function getSpaceScope(): ?array
    {
        return $this->spaceScope;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return null !== $this->expiresAt && ($now ?? new \DateTimeImmutable()) > $this->expiresAt;
    }

    /**
     * Both conditions in one place, so a second caller cannot check only one.
     */
    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    /**
     * Revoking is idempotent and keeps the first moment.
     *
     * Overwriting the timestamp on a second call would quietly rewrite when
     * access actually ended — which is the one fact an incident review needs.
     */
    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
