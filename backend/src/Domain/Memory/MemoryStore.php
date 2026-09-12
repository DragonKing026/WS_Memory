<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * Port: the memory engine, whatever it happens to be.
 *
 * Today it is MemPalace behind an HTTP MCP endpoint (D-001). The interface is
 * written so that replacing it — or upgrading it across a breaking change — is a
 * new class in Infrastructure and nothing else: no method here mentions
 * JSON-RPC, a tool name or a wire format.
 *
 * The signature that matters is search(). Its first argument is a mandatory,
 * non-empty PalaceWing, which makes an unfiltered search (inviolable rule 3)
 * impossible to write rather than merely forbidden. One wing per call is not an
 * oversight either: the palace filters by a single wing, so reading several
 * spaces means several calls, and the fan-out belongs to the layer that knows
 * which spaces the actor may read.
 */
interface MemoryStore
{
    /**
     * @return list<MemoryFragment> ordered by descending relevance
     *
     * @throws MemoryUnavailable
     */
    public function search(PalaceWing $wing, MemoryQuery $query): array;

    /**
     * @throws MemoryUnavailable
     */
    public function fetch(DrawerId $drawer): ?MemoryFragment;

    /**
     * @param string $addedBy who filed this, as recorded in the palace
     *
     * @throws MemoryUnavailable
     */
    public function store(
        PalaceWing $wing,
        MemoryKind $kind,
        string $content,
        string $addedBy,
        ?string $sourceFile = null,
    ): DrawerId;

    /**
     * @param 'outgoing'|'incoming'|'both'|null $direction
     *
     * @return list<KnowledgeFact>
     *
     * @throws MemoryUnavailable
     */
    public function queryFacts(string $entity, ?string $direction = null): array;

    /**
     * @throws MemoryUnavailable
     */
    public function addFact(KnowledgeFact $fact): void;

    /**
     * @throws MemoryUnavailable
     */
    public function writeDiary(
        PalaceWing $wing,
        string $agentName,
        string $entry,
        ?string $topic = null,
    ): DrawerId;
}
