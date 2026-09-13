<?php

declare(strict_types=1);

namespace App\Domain\Instruction;

/**
 * The caller asked for a URI that is not published.
 *
 * Distinct from InstructionUnavailable on purpose: this one is the caller's
 * mistake and the answer is a list of what exists, while the other one is ours
 * and the answer belongs in the server log.
 */
final class UnknownInstruction extends \RuntimeException
{
    public static function uri(string $uri): self
    {
        return new self(\sprintf(
            'Nie ma instrukcji pod adresem „%s". Wywołaj resources/list, żeby zobaczyć dostępne.',
            $uri,
        ));
    }
}
