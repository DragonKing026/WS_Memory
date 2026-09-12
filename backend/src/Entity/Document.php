<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Document\DocumentSlug;
use App\Domain\Document\DocumentStatus;
use App\Domain\Identity\Actor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A canonical document: the class of knowledge for which versioning earns its keep.
 *
 * The source of truth is this table, not the palace (D-004). The palace has no
 * notion of a revision, so a document lives here and a **copy** of its current
 * content is published there for semantic search.
 *
 * Two flags that are easy to confuse:
 *
 *   - `authoredByAi` says the latest revision was written by an agent. It is
 *     information, not a gate — the document is readable and searchable at once
 *     (D-005);
 *   - `verifiedBy` says a person stands behind it. Verification is cleared by every
 *     new revision, because "Anna checked this" stops being true the moment the
 *     text changes. That clearing is the whole value of the flag.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents', schema: 'ws')]
#[ORM\UniqueConstraint(name: 'uniq_documents_space_slug', columns: ['space_id', 'slug'])]
class Document
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: false, onDelete: 'RESTRICT')]
    private Space $space;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(length: 300)]
    private string $title;

    #[ORM\Column(length: 20, enumType: DocumentStatus::class, options: ['default' => 'draft'])]
    private DocumentStatus $status = DocumentStatus::Draft;

    #[ORM\ManyToOne(targetEntity: DocumentRevision::class)]
    #[ORM\JoinColumn(name: 'current_revision_id', nullable: true)]
    private ?DocumentRevision $currentRevision = null;

    /** @var Collection<int, DocumentRevision> */
    #[ORM\OneToMany(targetEntity: DocumentRevision::class, mappedBy: 'document')]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $revisions;

    #[ORM\Column(name: 'authored_by_ai', options: ['default' => false])]
    private bool $authoredByAi = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'verified_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $verifiedBy = null;

    #[ORM\Column(name: 'verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(Space $space, DocumentSlug $slug, string $title)
    {
        $this->id = Uuid::v7();
        $this->space = $space;
        $this->slug = $slug->value;
        $this->title = self::trimmedTitle($title);
        $this->revisions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Adds a revision and makes it current.
     *
     * Everything that has to happen together happens here: the number, the current
     * pointer, the title, the AI flag and **clearing the verification**. Spread
     * across a service, the last of those is the one somebody forgets, and the
     * result is a document marked as checked by a person who never saw this text.
     */
    public function addRevision(string $content, string $title, Actor $author, ?string $changeNote = null): DocumentRevision
    {
        if ($this->isArchived()) {
            throw new \DomainException('Dokument jest zarchiwizowany — przywróć go, zanim dopiszesz rewizję.');
        }

        $revision = new DocumentRevision(
            document: $this,
            number: $this->nextRevisionNumber(),
            content: $content,
            titleAtRevision: self::trimmedTitle($title),
            author: $author,
            changeNote: $changeNote,
        );

        $this->revisions->add($revision);
        $this->currentRevision = $revision;
        $this->title = self::trimmedTitle($title);
        $this->authoredByAi = $author->isAgent();
        $this->updatedAt = new \DateTimeImmutable();

        // A new text is not the text anybody verified.
        $this->verifiedBy = null;
        $this->verifiedAt = null;

        return $revision;
    }

    /**
     * Marks the document as checked by a person.
     *
     * Takes a User, not an Actor, and that is the type doing the work: an agent
     * cannot be passed in at all, so "an agent verifies its own writing" is
     * unrepresentable rather than forbidden (D-005).
     */
    public function verify(User $person): void
    {
        if ($this->isArchived()) {
            throw new \DomainException('Nie weryfikujemy zarchiwizowanego dokumentu.');
        }

        if (null === $this->currentRevision) {
            throw new \DomainException('Nie ma czego weryfikować — dokument nie ma jeszcze rewizji.');
        }

        $this->verifiedBy = $person;
        $this->verifiedAt = new \DateTimeImmutable();
        $this->status = DocumentStatus::Published;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function publish(): void
    {
        $this->status = DocumentStatus::Published;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Archives rather than deletes. Revision history never shrinks (D-007).
     */
    public function archive(): void
    {
        $this->archivedAt ??= new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->archivedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSpace(): Space
    {
        return $this->space;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function getCurrentRevision(): ?DocumentRevision
    {
        return $this->currentRevision;
    }

    public function getCurrentRevisionNumber(): int
    {
        return $this->currentRevision?->getNumber() ?? 0;
    }

    /** @return Collection<int, DocumentRevision> */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function revision(int $number): ?DocumentRevision
    {
        foreach ($this->revisions as $revision) {
            if ($revision->getNumber() === $number) {
                return $revision;
            }
        }

        return null;
    }

    public function isAuthoredByAi(): bool
    {
        return $this->authoredByAi;
    }

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function isVerified(): bool
    {
        return null !== $this->verifiedBy;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    private function nextRevisionNumber(): int
    {
        $highest = 0;
        foreach ($this->revisions as $revision) {
            $highest = max($highest, $revision->getNumber());
        }

        // From the highest ever, not from the count: revisions are never deleted,
        // so the two agree — and if they ever stopped agreeing, reusing a number
        // would silently overwrite a version somebody had linked to.
        return $highest + 1;
    }

    private static function trimmedTitle(string $title): string
    {
        $title = trim($title);

        if ('' === $title) {
            throw new \InvalidArgumentException('Dokument musi mieć tytuł.');
        }

        return mb_substr($title, 0, 300);
    }
}
