<?php

declare(strict_types=1);

namespace App\Domain\Instruction;

/**
 * An instruction together with what an agent is meant to read.
 *
 * The text is the document's body only: the wrapper's front matter — the fields
 * a plugin packaging needs — is metadata about the file, not instruction for the
 * model, and carrying it through would put it into the agent's context.
 */
final readonly class InstructionText
{
    public function __construct(
        public Instruction $instruction,
        public string $text,
    ) {
    }
}
