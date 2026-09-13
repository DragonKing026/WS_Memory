<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

use App\Domain\Memory\MemoryWrite;

/**
 * One drawer as a local palace sent it.
 *
 * Two fields are what make republishing safe, and they are the reason this type
 * exists rather than a bare string of content: the replica (on the batch) and
 * `sourceDrawerId` (here) form the pair `ws.memory_entries` holds unique. A
 * second publication of the same local drawer therefore updates its row instead
 * of adding one, which is what lets the outbox retry forever without consequence
 * (D-015).
 *
 * `sourceRoom` is carried for routing, not for filing. A mirror can exclude rooms,
 * so the rule needs to know which room this came from — but the room this drawer
 * ends up in on the server comes from MemoryKind, because that mapping has exactly
 * one owner and content filed into a room nobody looks in is content lost silently
 * (see MemoryKind).
 *
 * `sourcePath` is what the miner recorded as the origin of the text. It is passed
 * to the secret filter, because some secrets are recognisable only by where they
 * came from: a file named `.env` is not published whatever is inside it today.
 */
final readonly class IncomingDrawer
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $sourceDrawerId,
        public string $sourceWing,
        public ?string $sourceRoom,
        public string $content,
        public ?\DateTimeImmutable $filedAt = null,
        public ?string $title = null,
        public array $tags = [],
        public ?string $sourcePath = null,
    ) {
        if ('' === trim($sourceDrawerId)) {
            throw new \InvalidArgumentException('Szuflada z lokalnego pałaca musi mieć identyfikator.');
        }
        if ('' === trim($sourceWing)) {
            throw new \InvalidArgumentException('Szuflada z lokalnego pałaca musi mieć skrzydło źródłowe.');
        }
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Nie publikujemy pustej szuflady.');
        }
    }

    /**
     * Delegated rather than computed here, deliberately.
     *
     * The duplicate check reads this hash and the registry row stores one; if the
     * two were computed in two places they would eventually be computed
     * differently, and the failure is silent — nothing matches, and every
     * duplicate is published. MemoryWrite owns the derivation.
     */
    public function contentHash(): string
    {
        return MemoryWrite::hashOf($this->content);
    }
}
