<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\Actor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One version of a document, stored whole.
 *
 * Immutable once written. There is no setter here and that is the design: the way
 * to change a document is to add a revision, so history cannot be rewritten by
 * anybody using this class correctly.
 *
 * Exactly one author — a person or an agent token, never both and never neither
 * (enforced by a CHECK in the database as well). A revision with no author is
 * unattributable, and attribution is most of what a revision history is for.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_revisions', schema: 'ws')]
#[ORM\UniqueConstraint(name: 'uniq_revisions_document_number', columns: ['document_id', 'number'])]
class DocumentRevision
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /**
     * The title as it stood at this revision.
     *
     * Copied rather than read from the document, because a title changes too and a
     * history showing today's title on every old revision would misrepresent what
     * the document said at the time.
     */
    #[ORM\Column(name: 'title_at_revision', length: 300)]
    private string $titleAtRevision;

    #[ORM\Column(name: 'author_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $authorUserId = null;

    /**
     * No foreign key, as in `audit_log`: what a credential wrote must not be
     * erasable by deleting the credential.
     */
    #[ORM\Column(name: 'author_agent_token_id', type: 'uuid', nullable: true)]
    private ?Uuid $authorAgentTokenId = null;

    #[ORM\Column(name: 'change_note', length: 500, nullable: true)]
    private ?string $changeNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Document $document,
        int $number,
        string $content,
        string $titleAtRevision,
        Actor $author,
        ?string $changeNote = null,
    ) {
        $this->id = Uuid::v7();
        $this->document = $document;
        $this->number = $number;
        $this->content = $content;
        $this->titleAtRevision = $titleAtRevision;
        $this->changeNote = null === $changeNote ? null : mb_substr(trim($changeNote), 0, 500);
        $this->createdAt = new \DateTimeImmutable();

        // The one place that turns an Actor into authorship columns. An agent's
        // revision records both its token and its owner: "which credential wrote
        // this" and "who is answerable for it" are different questions, and an
        // incident review needs both.
        $this->authorUserId = Uuid::fromString($author->userId);
        $this->authorAgentTokenId = $author->isAgent() && null !== $author->agentTokenId
            ? Uuid::fromString($author->agentTokenId)
            : null;

        // The CHECK allows exactly one. For an agent the owner column is therefore
        // left empty — the owner stays reachable through the token, and the audit
        // log holds both.
        if (null !== $this->authorAgentTokenId) {
            $this->authorUserId = null;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getTitleAtRevision(): string
    {
        return $this->titleAtRevision;
    }

    public function getAuthorUserId(): ?Uuid
    {
        return $this->authorUserId;
    }

    public function getAuthorAgentTokenId(): ?Uuid
    {
        return $this->authorAgentTokenId;
    }

    public function isByAgent(): bool
    {
        return null !== $this->authorAgentTokenId;
    }

    public function getChangeNote(): ?string
    {
        return $this->changeNote;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
