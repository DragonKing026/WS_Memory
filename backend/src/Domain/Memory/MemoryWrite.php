<?php

declare(strict_types=1);

namespace App\Domain\Memory;

use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceId;

/**
 * One row to be written into the registry of everything of ours in the palace.
 *
 * A parameter object rather than eight arguments, because the list is going to
 * grow: publishing from a local palace (TODO-012) adds the replica it came from,
 * the drawer id it had there and the batch it arrived in. Those fields are
 * declared already, defaulted to null, so the bridge will add a caller rather
 * than change a signature every implementation has to follow.
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
    ) {
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
            hash('sha256', $content),
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
            hash('sha256', $content),
            [],
            $documentId,
        );
    }
}
