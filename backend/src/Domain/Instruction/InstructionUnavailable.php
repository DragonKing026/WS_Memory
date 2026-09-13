<?php

declare(strict_types=1);

namespace App\Domain\Instruction;

/**
 * A published instruction whose source could not be read.
 *
 * Raised rather than answered with an empty document, because an empty
 * instruction is worse than a missing one: the agent reads it as "there is no
 * protocol" and proceeds. A deployment that mounted the wrong directory has to
 * be loud.
 */
final class InstructionUnavailable extends \RuntimeException
{
    public static function source(string $uri, string $path, string $reason): self
    {
        return new self(\sprintf('Instrukcja „%s" jest zadeklarowana, ale nie da się jej odczytać z %s: %s.', $uri, $path, $reason));
    }
}
