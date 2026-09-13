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
     * Replaces the content of a drawer that already exists.
     *
     * Exists because a document is republished on every revision, and filing a new
     * drawer each time would leave older versions searchable — an agent would find
     * the superseded text and have no way to tell. One drawer per document, updated
     * in place, keeps "what does the wiki say" a question with one answer.
     *
     * Returns the identifier the content now lives under. Usually the one passed in;
     * a different one when the drawer had vanished and had to be filed afresh, which
     * the caller must then record.
     *
     * @throws MemoryUnavailable
     */
    public function replace(
        DrawerId $drawer,
        PalaceWing $wing,
        MemoryKind $kind,
        string $content,
        string $addedBy,
    ): DrawerId;

    /**
     * Removes a drawer from the engine for good.
     *
     * Exists for undoing a publication (TODO-012), and it has to go through this
     * port rather than through SQL against the `palace` schema — that would be the
     * same mistake in the opposite direction as writing there (D-004): our
     * connection can read those tables, so deleting a row would appear to work
     * while leaving the vector, the graph edges and whatever else MemPalace keys
     * off that drawer behind. The engine is a black box (D-001); asking it to
     * forget is the only way to be sure it has.
     *
     * A drawer that is not there is not an error. Undoing is retried after a
     * partial failure, and the second attempt finds the first attempt's work
     * already done — treating that as a failure would make undo unrepeatable
     * exactly when it needs repeating.
     *
     * @return bool whether the engine held it
     *
     * @throws MemoryUnavailable
     */
    public function forget(DrawerId $drawer): bool;

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
