<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Document\ProposalStatus;
use App\Domain\Identity\Actor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An entry in the review queue, for spaces that ask for one.
 *
 * Active only where `spaces.requires_proposal` is set. Everywhere else an agent
 * writes directly: versioning is the safety net, not an approval queue (AGENTS.md,
 * section 4). The queue exists for the few spaces where content really does need a
 * person to look first.
 *
 * Accepting a proposal records the reviewer as the author of the resulting
 * revision, and keeps the fact that the text came from an agent. Both halves
 * matter: somebody is answerable for what was accepted, and nobody should later be
 * surprised to learn a machine wrote it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'proposals', schema: 'ws')]
#[ORM\Index(name: 'idx_proposals_space_status', columns: ['space_id', 'status'])]
class Proposal
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: false, onDelete: 'RESTRICT')]
    private Space $space;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 300)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(name: 'author_agent_token_id', type: 'uuid', nullable: true)]
    private ?Uuid $authorAgentTokenId = null;

    #[ORM\Column(name: 'author_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $authorUserId = null;

    #[ORM\Column(length: 20, enumType: ProposalStatus::class, options: ['default' => 'pending'])]
    private ProposalStatus $status = ProposalStatus::Pending;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $reviewedBy = null;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(name: 'review_note', length: 500, nullable: true)]
    private ?string $reviewNote = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'resulting_document_id', nullable: true, onDelete: 'SET NULL')]
    private ?Document $resultingDocument = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Space $space,
        string $title,
        string $content,
        Actor $author,
        ?string $slug = null,
    ) {
        $this->id = Uuid::v7();
        $this->space = $space;
        $this->title = trim($title);
        $this->content = $content;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();

        $this->authorUserId = Uuid::fromString($author->userId);
        $this->authorAgentTokenId = $author->isAgent() && null !== $author->agentTokenId
            ? Uuid::fromString($author->agentTokenId)
            : null;
    }

    /**
     * Closes the proposal as accepted, pointing at what came of it.
     *
     * Idempotent in the direction that matters: a proposal already closed is not
     * reopened, so two reviewers clicking at once cannot produce two documents from
     * one proposal.
     */
    public function accept(User $reviewer, Document $document, ?string $note = null): void
    {
        $this->ensureOpen();

        $this->status = ProposalStatus::Accepted;
        $this->resultingDocument = $document;
        $this->close($reviewer, $note);
    }

    public function reject(User $reviewer, ?string $note = null): void
    {
        $this->ensureOpen();

        $this->status = ProposalStatus::Rejected;
        $this->close($reviewer, $note);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSpace(): Space
    {
        return $this->space;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function getAuthorAgentTokenId(): ?Uuid
    {
        return $this->authorAgentTokenId;
    }

    public function getAuthorUserId(): ?Uuid
    {
        return $this->authorUserId;
    }

    public function isByAgent(): bool
    {
        return null !== $this->authorAgentTokenId;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function getResultingDocument(): ?Document
    {
        return $this->resultingDocument;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function ensureOpen(): void
    {
        if (!$this->isOpen()) {
            throw new \DomainException('Ta propozycja została już rozpatrzona.');
        }
    }

    private function close(User $reviewer, ?string $note): void
    {
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewNote = null === $note ? null : mb_substr(trim($note), 0, 500);
    }
}
