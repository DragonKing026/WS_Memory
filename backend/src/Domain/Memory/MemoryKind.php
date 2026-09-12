<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * What class of knowledge a piece of memory is (AGENTS.md, section 4).
 *
 * The values are exactly the ones `ws.memory_entries.kind` accepts, so that a
 * kind the database rejects cannot be constructed in the first place.
 *
 * This enum also owns the kind-to-room mapping, and that is the point of it.
 * The palace files content into rooms; we file it by kind. If the write path
 * chose the room and the read path chose it again, the two would eventually
 * disagree, and the consequence is peculiarly nasty: nothing fails, the content
 * is simply stored where nobody looks for it. One mapping, one place.
 */
enum MemoryKind: string
{
    /** A finding or note an agent wrote on purpose (ws_remember). */
    case Note = 'note';

    /** Canonical documentation — a wiki document published into the palace. */
    case Document = 'document';

    /** A session diary entry. */
    case Diary = 'diary';

    /** A knowledge-graph triple. Registered, but never filed as a drawer. */
    case KgFact = 'kg_fact';

    /** Raw material arriving from a local palace (TODO-012). */
    case Transcript = 'transcript';

    /**
     * The palace room this kind is filed into.
     *
     * @throws \LogicException for a kind that is not a drawer at all
     */
    public function room(): string
    {
        return match ($this) {
            self::Note => 'technical',
            self::Document => 'documentation',
            self::Diary => 'diary',
            self::Transcript => 'general',
            // Not a defensive check but a statement of fact: the palace exposes
            // no room for graph facts, so asking for one means the caller has
            // confused a fact with a drawer. Failing loudly here is cheaper
            // than filing facts into an arbitrary room.
            self::KgFact => throw new \LogicException('A knowledge-graph fact is not filed as a drawer and has no room.'),
        };
    }

    public function isDrawer(): bool
    {
        return self::KgFact !== $this;
    }
}
