<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Domain\Audit\AuditTrail;
use App\Domain\Document\DocumentSlug;
use App\Domain\Document\RevisionDiff;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only way in and out of the wiki.
 *
 * Both surfaces call this class — REST for people, `ws_doc_*` for agents (D-008) —
 * so a permission rule exists once and choosing a different door cannot get you a
 * different answer. It is the document equivalent of MemoryService.
 *
 * Three rules are enforced here rather than trusted:
 *
 *  - a document in a space the actor cannot read is **not found**, never forbidden.
 *    A 403 would confirm what a space contains (inviolable rule 7);
 *  - verification takes a User and not an Actor, so an agent confirming its own
 *    writing is unrepresentable (D-005);
 *  - a rollback **adds** a revision with old content. Nothing is ever deleted, so
 *    the history of what the document said cannot shrink.
 *
 * Publishing to the palace is dispatched, not done here: an embedding over a whole
 * document takes seconds and the person pressing save should not wait for it.
 */
final readonly class DocumentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpaceAccessResolver $access,
        private MessageBusInterface $bus,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Creates a document or adds a revision to it.
     *
     * @throws MemoryAccessDenied when the actor may not write in that space
     * @throws ProposalRequired   when the space queues agent writes instead
     */
    public function write(
        Actor $actor,
        SpaceId $space,
        DocumentSlug $slug,
        string $title,
        string $content,
        ?string $changeNote = null,
    ): DocumentRevision {
        $entity = $this->writableSpace($actor, $space);

        // The queue applies to agents only. A person writing in such a space is the
        // reviewer, not somebody to be reviewed — putting them in their own queue
        // would mean nobody could ever empty it.
        if ($actor->isAgent() && $entity->requiresProposal()) {
            throw ProposalRequired::in($space);
        }

        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Dokument nie może być pusty.');
        }

        $document = $this->find($entity, $slug);

        if (null === $document) {
            $document = new Document($entity, $slug, $title);
            $this->entityManager->persist($document);
        } elseif ($document->isArchived()) {
            // Writing to an archived document restores it. The alternative — an
            // error — sends the caller looking for an "unarchive" button that the
            // act of writing already implies.
            $document->restore();
        }

        $revision = $document->addRevision($content, $title, $actor, $changeNote);
        $this->entityManager->persist($revision);

        // A document written by a person is published outright; an agent's is too
        // (D-005), and the draft state is only for a human who has not finished.
        $document->publish();

        $this->audit->record('document.write', $actor, $space->value, [
            'document' => $document->getId()->toRfc4122(),
            'slug' => $slug->value,
            'revision' => $revision->getNumber(),
            'by_ai' => $actor->isAgent(),
        ]);

        $this->entityManager->flush();

        // Dispatched after the flush: a message for a revision that failed to save
        // would have the worker publishing content nobody can read.
        $this->bus->dispatch(new PublishDocument(
            $document->getId()->toRfc4122(),
            $revision->getNumber(),
        ));

        return $revision;
    }

    /**
     * A document the actor may read, or null.
     */
    public function read(Actor $actor, SpaceId $space, DocumentSlug $slug): ?Document
    {
        if (!$this->access->canRead($actor, $space)) {
            return null;
        }

        $entity = $this->spaceEntity($space);

        return null === $entity ? null : $this->find($entity, $slug);
    }

    /**
     * @throws DocumentNotFound
     */
    public function require(Actor $actor, SpaceId $space, DocumentSlug $slug): Document
    {
        return $this->read($actor, $space, $slug) ?? throw DocumentNotFound::create();
    }

    /**
     * Documents in a space, newest change first.
     *
     * @return list<Document>
     */
    public function list(Actor $actor, SpaceId $space, bool $includeArchived = false): array
    {
        if (!$this->access->canRead($actor, $space)) {
            // An empty list rather than an error: the caller learns nothing about
            // whether the space exists.
            return [];
        }

        $entity = $this->spaceEntity($space);
        if (null === $entity) {
            return [];
        }

        $criteria = ['space' => $entity];
        if (!$includeArchived) {
            $criteria['archivedAt'] = null;
        }

        /** @var list<Document> $documents */
        $documents = $this->entityManager->getRepository(Document::class)
            ->findBy($criteria, ['updatedAt' => 'DESC']);

        return $documents;
    }

    /**
     * Restores an earlier revision by writing a **new** one with its content.
     *
     * Never by deleting what came after. A history that can shrink is not a history,
     * and the whole reason documents are versioned is to be able to look at what they
     * said before somebody was sure it was wrong.
     *
     * @throws DocumentNotFound  when the revision does not exist
     * @throws MemoryAccessDenied
     */
    public function rollback(
        Actor $actor,
        SpaceId $space,
        DocumentSlug $slug,
        int $toRevision,
    ): DocumentRevision {
        $this->writableSpace($actor, $space);

        $document = $this->require($actor, $space, $slug);
        $target = $document->revision($toRevision) ?? throw DocumentNotFound::create();

        $note = \sprintf('Cofnięcie do rewizji %d', $toRevision);
        $revision = $document->addRevision(
            $target->getContent(),
            $target->getTitleAtRevision(),
            $actor,
            $note,
        );
        $this->entityManager->persist($revision);

        $this->audit->record('document.rollback', $actor, $space->value, [
            'document' => $document->getId()->toRfc4122(),
            'to_revision' => $toRevision,
            'new_revision' => $revision->getNumber(),
        ]);

        $this->entityManager->flush();

        $this->bus->dispatch(new PublishDocument(
            $document->getId()->toRfc4122(),
            $revision->getNumber(),
        ));

        return $revision;
    }

    /**
     * The difference between two revisions.
     *
     * @throws DocumentNotFound
     */
    public function diff(Actor $actor, SpaceId $space, DocumentSlug $slug, int $from, int $to): RevisionDiff
    {
        $document = $this->require($actor, $space, $slug);

        $before = $document->revision($from) ?? throw DocumentNotFound::create();
        $after = $document->revision($to) ?? throw DocumentNotFound::create();

        return RevisionDiff::between($before->getContent(), $after->getContent());
    }

    /**
     * Marks a document as checked by a person.
     *
     * Takes a User rather than an Actor on purpose: there is no way to pass an agent,
     * so "an agent verifies its own entry" cannot be expressed (D-005). The writer
     * role is enough — somebody trusted to change the text is trusted to vouch for it.
     *
     * @throws DocumentNotFound
     * @throws MemoryAccessDenied
     */
    public function verify(User $person, SpaceId $space, DocumentSlug $slug): Document
    {
        $actor = Actor::human($person->getId()->toRfc4122(), $person->isGlobalAdmin());
        $this->writableSpace($actor, $space);

        $document = $this->require($actor, $space, $slug);
        $document->verify($person);

        $this->audit->record('document.verified', $actor, $space->value, [
            'document' => $document->getId()->toRfc4122(),
            'revision' => $document->getCurrentRevisionNumber(),
        ]);

        $this->entityManager->flush();

        return $document;
    }

    /**
     * Archives a document. Its revisions stay where they are.
     *
     * @throws DocumentNotFound
     * @throws MemoryAccessDenied
     */
    public function archive(Actor $actor, SpaceId $space, DocumentSlug $slug): Document
    {
        $this->writableSpace($actor, $space);

        $document = $this->require($actor, $space, $slug);
        $document->archive();

        $this->audit->record('document.archived', $actor, $space->value, [
            'document' => $document->getId()->toRfc4122(),
        ]);

        $this->entityManager->flush();

        return $document;
    }

    /**
     * The space entity, having established the actor may write in it.
     *
     * @throws MemoryAccessDenied
     */
    private function writableSpace(Actor $actor, SpaceId $space): Space
    {
        if (!$this->access->canWrite($actor, $space)) {
            // Same message for "no such space" and "read-only for you": the caller
            // named the space, so it already knows whether it meant to.
            throw MemoryAccessDenied::write($space);
        }

        return $this->spaceEntity($space)
            ?? throw new \DomainException(\sprintf('Przestrzeń „%s" nie istnieje.', $space->value));
    }

    private function spaceEntity(SpaceId $space): ?Space
    {
        return $this->entityManager->getRepository(Space::class)->findOneBy(['slug' => $space->value]);
    }

    private function find(Space $space, DocumentSlug $slug): ?Document
    {
        return $this->entityManager->getRepository(Document::class)
            ->findOneBy(['space' => $space, 'slug' => $slug->value]);
    }
}
