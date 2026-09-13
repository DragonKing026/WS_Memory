<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A record of who did what, when and from where.
 *
 * Append-only by design: entries are never edited or deleted, because an audit
 * trail that can be rewritten is not evidence. The retention policy aggregates
 * old entries into statistics rather than mutating them
 * (docs/05-deployment.md).
 *
 * The actor is stored as a plain identifier rather than a relation, so that
 * deactivating an account never cascades into the history of what it did.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log', schema: 'ws')]
#[ORM\Index(name: 'idx_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_user_id'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
// The two below serve the administration screen's filters (TODO-008). The measurements
// that justify them are in Version20260913000003; the short version is that filtering by
// space or by agent token was a sequential scan of a table that grows without bound.
#[ORM\Index(name: 'idx_audit_space', columns: ['space_slug', 'created_at'])]
#[ORM\Index(name: 'idx_audit_actor_token', columns: ['actor_agent_token_id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'actor_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $actorUserId = null;

    #[ORM\Column(name: 'actor_agent_token_id', type: 'uuid', nullable: true)]
    private ?Uuid $actorAgentTokenId = null;

    #[ORM\Column(length: 60)]
    private string $action;

    #[ORM\Column(name: 'space_slug', length: 100, nullable: true)]
    private ?string $spaceSlug = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $target = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $target */
    public function __construct(
        string $action,
        ?Uuid $actorUserId = null,
        ?Uuid $actorAgentTokenId = null,
        ?string $spaceSlug = null,
        array $target = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ) {
        $this->id = Uuid::v7();
        $this->action = $action;
        $this->actorUserId = $actorUserId;
        $this->actorAgentTokenId = $actorAgentTokenId;
        $this->spaceSlug = $spaceSlug;
        $this->target = $target;
        $this->ip = $ip;
        $this->userAgent = mb_substr((string) $userAgent, 0, 255) ?: null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
