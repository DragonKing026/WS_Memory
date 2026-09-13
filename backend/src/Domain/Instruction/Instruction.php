<?php

declare(strict_types=1);

namespace App\Domain\Instruction;

/**
 * One instruction document, described without its text.
 *
 * Metadata and content are separate because a client asks for the catalogue far
 * more often than for a document: an MCP client lists resources on every
 * connection and reads one only when it needs it.
 *
 * The fields are the ones an MCP resource entry carries. `name` is the machine
 * handle an agent refers to (`ws-memory-recall`), `title` the human one.
 */
final readonly class Instruction
{
    public function __construct(
        public string $uri,
        public string $name,
        public string $title,
        public string $description,
        public string $mimeType,
    ) {
        foreach (['uri' => $uri, 'name' => $name, 'title' => $title, 'mimeType' => $mimeType] as $field => $value) {
            if ('' === trim($value)) {
                // An entry with a blank uri or name is unusable to a client and
                // silently so — it lists fine and cannot be read.
                throw new \InvalidArgumentException(\sprintf('Instrukcja musi mieć niepuste pole „%s".', $field));
            }
        }
    }
}
