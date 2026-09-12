<?php

declare(strict_types=1);

namespace App\Application\Document;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Identity\AgentTokenDirectory;
use App\Domain\Space\SpaceId;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishes a document's current content into the palace.
 *
 * Two properties matter more than the work itself.
 *
 * **Idempotent.** Publishing the same revision twice updates the same drawer with
 * the same content. Messenger retries on failure, and a handler that filed a second
 * copy on every retry would fill the palace with duplicates of the same page.
 *
 * **Safe out of order.** Three quick saves queue three messages, and the queue makes
 * no promise about order. A message whose revision number is behind the document's
 * current one is **dropped**, not published — otherwise a late-arriving older
 * message would overwrite the newest text with a superseded version, and the wiki
 * and the search results would disagree with nobody able to see why.
 */
#[AsMessageHandler]
final readonly class PublishDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MemoryService $memory,
        private AgentTokenDirectory $tokens,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Who to record as the author of the palace copy.
     *
     * The revision's own author, not "the worker" — the registry row answers "who put
     * this here", and the useful answer is the person or agent that wrote the text.
     *
     * An agent's revision records only its token, because exactly one author column
     * may be set. The owner is therefore looked up: a registry row needs a person, and
     * a token always has one.
     */
    private function authorOf(DocumentRevision $revision): Actor
    {
        $tokenId = $revision->getAuthorAgentTokenId()?->toRfc4122();

        if (null === $tokenId) {
            $userId = $revision->getAuthorUserId()?->toRfc4122()
                ?? throw new \DomainException('Rewizja bez autora — nie ma czego zapisać w rejestrze.');

            return Actor::human($userId);
        }

        $owner = $this->tokens->ownerOf($tokenId)
            // Deliberately loud. Retries land the message in the failed transport,
            // where a person can see it — better than publishing the document under
            // somebody arbitrary, which the audit trail would then assert as fact.
            ?? throw new \DomainException(\sprintf('Nie znaleziono właściciela tokena %s.', $tokenId));

        return Actor::agent($owner, $tokenId);
    }

    public function __invoke(PublishDocument $message): void
    {
        $document = $this->entityManager->getRepository(Document::class)->find($message->documentId);

        if (!$document instanceof Document) {
            // Deleted between the save and this message. Nothing to publish and
            // nothing wrong — logged rather than retried, because a retry would find
            // exactly the same nothing.
            $this->logger->info('Dokument do publikacji już nie istnieje.', [
                'document' => $message->documentId,
            ]);

            return;
        }

        $revision = $document->getCurrentRevision();
        if (null === $revision) {
            $this->logger->warning('Dokument bez rewizji trafił do publikacji.', [
                'document' => $message->documentId,
            ]);

            return;
        }

        if ($revision->getNumber() > $message->revisionNumber) {
            // The ordering guard. Not an error: it is the expected outcome of saving
            // three times quickly, and the newest message will do the work.
            $this->logger->info('Pomijam publikację nieaktualnej rewizji.', [
                'document' => $message->documentId,
                'zlecona' => $message->revisionNumber,
                'aktualna' => $revision->getNumber(),
            ]);

            return;
        }

        $this->memory->publishDocument(
            author: $this->authorOf($revision),
            space: new SpaceId($document->getSpace()->getSlug()),
            documentId: $document->getId()->toRfc4122(),
            title: $document->getTitle(),
            content: $revision->getContent(),
        );
    }
}
