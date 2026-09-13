<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceId;

/**
 * One row to be written into the registry of everything of ours in the palace.
 *
 * A parameter object rather than eight arguments, because the list was always
 * going to grow — and it has: publishing from a local palace (TODO-012) added the
 * replica it came from, the drawer id it had there, the batch it arrived in and
 * the time it was filed locally. Every one of them arrived as a named constructor
 * (self::fromReplica()) rather than as a new argument on a signature every
 * implementation of MemoryRegistry would have had to follow.
 */
final readonly class MemoryWrite
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public DrawerId $drawer,
        public SpaceId $space,
        public MemoryKind $kind,
        public Actor $author,
        public string $title,
        public string $contentHash,
        public array $tags = [],
        /** Set when this drawer is the published copy of a wiki document. */
        public ?string $documentId = null,
        public ?string $sourceReplica = null,
        public ?string $sourceDrawerId = null,
        public ?string $publishBatchId = null,
        /**
         * When the content was filed, as opposed to when we received it.
         *
         * Null means now, which is the right answer for anything written through
         * our own surfaces. It is the wrong answer for a publication: a laptop
         * that was offline for a week sends a week of drawers at once, and dating
         * them all "today" would make the browse screen — ordered by exactly this
         * column — claim a week of work happened in one minute.
         */
        public ?\DateTimeImmutable $filedAt = null,
    ) {
    }

    /**
     * The content hash, derived in one place for everybody who needs it.
     *
     * This is what stops three people mining the same repository from filing the
     * same drawer three times into one space (D-014). The publication path has to
     * compute it BEFORE writing, to look for a duplicate, and the registry row
     * stores it afterwards — two moments, and they must agree exactly. Computed
     * twice they would eventually differ, and the failure is silent: nothing
     * matches, and every duplicate is published.
     */
    public static function hashOf(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Derives title and content hash from the content itself.
     *
     * Both are derived in exactly one place on purpose. The hash is what stops
     * three people mining the same repository from publishing the same drawer
     * three times into one space, and a hash computed differently on two paths
     * would defeat that by never matching.
     *
     * @param list<string> $tags
     */
    public static function ofContent(
        DrawerId $drawer,
        SpaceId $space,
        MemoryKind $kind,
        Actor $author,
        string $content,
        array $tags = [],
        ?string $documentId = null,
    ): self {
        return new self(
            $drawer,
            $space,
            $kind,
            $author,
            MemoryFragment::titleOf($content),
            self::hashOf($content),
            $tags,
            $documentId,
        );
    }

    /**
     * The registry row for the published copy of a wiki document.
     *
     * A named constructor rather than arguments at the call site, because the title
     * comes from the document and not from the content: a document's title is a
     * field somebody wrote, while for a note it has to be guessed from the first
     * line. Losing that distinction would put "# Umowa najmu" in listings.
     */
    public static function forDocument(
        DrawerId $drawer,
        SpaceId $space,
        Actor $author,
        string $documentId,
        string $title,
        string $content,
    ): self {
        return new self(
            $drawer,
            $space,
            MemoryKind::Document,
            $author,
            mb_substr(trim($title), 0, 200),
            self::hashOf($content),
            [],
            $documentId,
        );
    }

    /**
     * The registry row for a drawer that arrived from somebody's local palace.
     *
     * The source pair — replica plus the drawer's local identifier — is the whole
     * reason this constructor exists. `ws.memory_entries` holds that pair unique
     * where both halves are set, so a second publication of the same local drawer
     * updates its row rather than adding one. Without it the outbox (D-015) could
     * not retry: every failed send that actually arrived would double the content.
     *
     * The title is taken from the local palace when it has one and guessed from
     * the content otherwise. A miner usually names what it filed, and its name is
     * better than our first-line guess.
     *
     * @param list<string> $tags
     */
    public static function fromReplica(
        DrawerId $drawer,
        SpaceId $space,
        Actor $author,
        string $sourceReplica,
        string $sourceDrawerId,
        string $publishBatchId,
        string $content,
        ?string $title = null,
        array $tags = [],
        ?\DateTimeImmutable $filedAt = null,
    ): self {
        return new self(
            $drawer,
            $space,
            MemoryKind::Transcript,
            $author,
            null === $title || '' === trim($title)
                ? MemoryFragment::titleOf($content)
                : mb_substr(trim($title), 0, 200),
            self::hashOf($content),
            $tags,
            null,
            $sourceReplica,
            $sourceDrawerId,
            $publishBatchId,
            $filedAt,
        );
    }
}
