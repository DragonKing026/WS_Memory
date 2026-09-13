<?php

declare(strict_types=1);

namespace App\Domain\Instruction;

/**
 * The instruction texts the gateway publishes as MCP resources.
 *
 * The content has one source in `plugin/shared/` and is served from the server
 * rather than copied into every packaging (D-013). Changing an instruction is
 * then a deployment, not an update everybody has to install — and a client that
 * is not Claude Code reads exactly the same text.
 *
 * A port, so that `Domain` says what is published while `Infrastructure` decides
 * where the bytes come from: a directory today, an object store or the database
 * the day instructions become editable in the application.
 */
interface InstructionLibrary
{
    /**
     * Every published instruction, in a stable order.
     *
     * @return list<Instruction>
     *
     * @throws InstructionUnavailable one of them cannot be read
     */
    public function all(): array;

    /**
     * @throws UnknownInstruction      the URI is not one of the published ones
     * @throws InstructionUnavailable  it is published, but unreadable
     */
    public function read(string $uri): InstructionText;
}
