<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Domain\Audit\AuditTrail;
use App\Domain\Document\DocumentSlug;
use App\Domain\Document\ProposalStatus;
use App\Domain\Identity\Actor;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Entity\Document;
use App\Entity\Proposal;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The review queue, for the few spaces that ask for one.
 *
 * A proposal needs only the **reader** role to submit, which looks lax until you ask
 * what the queue is for: it exists so content can be offered where writing directly
 * is not allowed. Requiring the writer role would make the queue reachable only by
 * people who did not need it.
 *
 * Accepting is a **write**, and it records the reviewer as the author of the
 * resulting revision while keeping the fact that an agent produced the text. Both
 * halves matter: somebody is answerable for what was accepted, and nobody should
 * later be surprised to learn a machine wrote it.
 */
final readonly class ProposalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentService $documents,
        private SpaceAccessResolver $access,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @throws MemoryAccessDenied when the actor cannot even read the space
     */
    public function propose(
        Actor $actor,
        SpaceId $space,
        string $title,
        string $content,
        ?DocumentSlug $slug = null,
    ): Proposal {
        if (!$this->access->canRead($actor, $space)) {
            throw MemoryAccessDenied::write($space);
        }

        if ('' === trim($content) || '' === trim($title)) {
            throw new \InvalidArgumentException('Propozycja musi mieć tytuł i treść.');
        }

        $entity = $this->spaceEntity($space)
            ?? throw new \DomainException(\sprintf('Przestrzeń „%s" nie istnieje.', $space->value));

        $proposal = new Proposal($entity, $title, $content, $actor, $slug?->value);
        $this->entityManager->persist($proposal);

        $this->audit->record('proposal.submitted', $actor, $space->value, [
            'proposal' => $proposal->getId()->toRfc4122(),
            'title' => $proposal->getTitle(),
            'by_ai' => $actor->isAgent(),
        ]);

        $this->entityManager->flush();

        return $proposal;
    }

    /**
     * Open proposals in a space, oldest first — a queue is worked from the front.
     *
     * @return list<Proposal>
     */
    public function pending(Actor $actor, SpaceId $space): array
    {
        if (!$this->access->canRead($actor, $space)) {
            return [];
        }

        $entity = $this->spaceEntity($space);
        if (null === $entity) {
            return [];
        }

        /** @var list<Proposal> $proposals */
        $proposals = $this->entityManager->getRepository(Proposal::class)->findBy(
            ['space' => $entity, 'status' => ProposalStatus::Pending],
            ['createdAt' => 'ASC'],
        );

        return $proposals;
    }

    /**
     * Turns a proposal into a document revision, authored by the reviewer.
     *
     * @throws DocumentNotFound   when there is no such open proposal the reviewer may see
     * @throws MemoryAccessDenied when the reviewer may not write in that space
     */
    public function accept(User $reviewer, string $proposalId, ?DocumentSlug $slug = null, ?string $note = null): Document
    {
        $proposal = $this->openProposal($reviewer, $proposalId);
        $space = new SpaceId($proposal->getSpace()->getSlug());

        $target = $slug
            ?? (null !== $proposal->getSlug() ? new DocumentSlug($proposal->getSlug()) : null)
            // Derived from the title only as a last resort: an agent that named no
            // address gets one, but a reviewer choosing it deliberately wins.
            ?? DocumentSlug::fromTitle($proposal->getTitle());

        // Through DocumentService, not by building a revision here: that is where the
        // permission check, the publication and the audit entry live, and a second
        // path into the wiki would eventually miss one of the three.
        $revision = $this->documents->write(
            actor: Actor::human($reviewer->getId()->toRfc4122(), $reviewer->isGlobalAdmin()),
            space: $space,
            slug: $target,
            title: $proposal->getTitle(),
            content: $proposal->getContent(),
            changeNote: \sprintf(
                'Przyjęta propozycja %s%s',
                $proposal->getId()->toRfc4122(),
                $proposal->isByAgent() ? ' (treść od agenta AI)' : '',
            ),
        );

        $document = $revision->getDocument();
        $proposal->accept($reviewer, $document, $note);

        $this->audit->record(
            'proposal.accepted',
            Actor::human($reviewer->getId()->toRfc4122(), $reviewer->isGlobalAdmin()),
            $space->value,
            [
                'proposal' => $proposal->getId()->toRfc4122(),
                'document' => $document->getId()->toRfc4122(),
                'revision' => $revision->getNumber(),
                'content_from_ai' => $proposal->isByAgent(),
            ],
        );

        $this->entityManager->flush();

        return $document;
    }

    /**
     * @throws DocumentNotFound
     * @throws MemoryAccessDenied
     */
    public function reject(User $reviewer, string $proposalId, ?string $note = null): Proposal
    {
        $proposal = $this->openProposal($reviewer, $proposalId);
        $proposal->reject($reviewer, $note);

        $this->audit->record(
            'proposal.rejected',
            Actor::human($reviewer->getId()->toRfc4122(), $reviewer->isGlobalAdmin()),
            $proposal->getSpace()->getSlug(),
            ['proposal' => $proposal->getId()->toRfc4122(), 'note' => $note],
        );

        $this->entityManager->flush();

        return $proposal;
    }

    /**
     * An open proposal the reviewer may act on.
     *
     * Missing, already reviewed and in-a-space-you-cannot-write all answer the same
     * way. Telling them apart would let anybody enumerate what other spaces are
     * being asked to publish.
     *
     * @throws DocumentNotFound
     * @throws MemoryAccessDenied
     */
    private function openProposal(User $reviewer, string $proposalId): Proposal
    {
        if (!Uuid::isValid($proposalId)) {
            throw DocumentNotFound::create();
        }

        $proposal = $this->entityManager->getRepository(Proposal::class)->find($proposalId);
        if (!$proposal instanceof Proposal || !$proposal->isOpen()) {
            throw DocumentNotFound::create();
        }

        $space = new SpaceId($proposal->getSpace()->getSlug());
        $actor = Actor::human($reviewer->getId()->toRfc4122(), $reviewer->isGlobalAdmin());

        if (!$this->access->canRead($actor, $space)) {
            // Not a write refusal: a stranger must not learn that the proposal exists.
            throw DocumentNotFound::create();
        }

        if (!$this->access->canWrite($actor, $space)) {
            throw MemoryAccessDenied::write($space);
        }

        return $proposal;
    }

    private function spaceEntity(SpaceId $space): ?Space
    {
        return $this->entityManager->getRepository(Space::class)->findOneBy(['slug' => $space->value]);
    }
}
