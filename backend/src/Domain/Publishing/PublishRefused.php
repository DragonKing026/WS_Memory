<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * A publication the server will not perform.
 *
 * Distinct from MemoryAccessDenied on purpose, and the difference is the HTTP
 * status the controller picks: a missing role is 403, a batch that is already
 * reverted is 409, a malformed request is 400. Folding all three into one
 * exception would make the endpoint answer "forbidden" to a client that merely
 * pressed undo twice — and an outbox retrying on 403 gives up for good.
 */
final class PublishRefused extends \DomainException
{
    private function __construct(
        string $message,
        public readonly PublishRefusal $refusal,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $why): self
    {
        return new self($why, PublishRefusal::Malformed);
    }

    public static function unknownBatch(string $id): self
    {
        return new self(\sprintf('Nie ma partii publikacji %s.', $id), PublishRefusal::UnknownBatch);
    }

    /**
     * Somebody else's batch.
     *
     * Not "forbidden" but "unknown", and the wording is chosen: telling a caller
     * that a batch exists but belongs to another person is itself a disclosure —
     * the same rule the document routes follow (inviolable rule 7).
     */
    public static function notYourBatch(string $id): self
    {
        return new self(\sprintf('Nie ma partii publikacji %s.', $id), PublishRefusal::UnknownBatch);
    }

    public static function alreadyReverted(string $id): self
    {
        return new self(\sprintf('Partia %s jest już wycofana.', $id), PublishRefusal::AlreadyReverted);
    }

    public static function notApplied(string $id): self
    {
        return new self(
            \sprintf('Partia %s nie została zastosowana — nie ma czego wycofywać.', $id),
            PublishRefusal::AlreadyReverted,
        );
    }
}
