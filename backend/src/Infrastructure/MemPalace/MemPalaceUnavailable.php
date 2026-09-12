<?php

declare(strict_types=1);

namespace App\Infrastructure\MemPalace;

use App\Domain\Memory\MemoryUnavailable;

/**
 * MemPalace did not give us an answer we can use.
 *
 * One exception for several causes — a refused connection, a timeout, an HTTP
 * 500, a malformed envelope, a tool reporting its own failure — and deliberately
 * not one class per cause. The caller's options are identical in every case:
 * tell whoever asked that memory is not answering right now, and do not pretend
 * the result was empty. A distinction nobody acts on is noise in a stack trace.
 *
 * The cause still reaches the log, in the message and the previous exception.
 * What never reaches it is the token: see MemPalaceClient.
 */
final class MemPalaceUnavailable extends MemoryUnavailable
{
    public static function transport(string $tool, \Throwable $cause): self
    {
        return new self(
            \sprintf('Pamięć nie odpowiada (narzędzie %s): %s', $tool, $cause->getMessage()),
            previous: $cause,
        );
    }

    public static function httpStatus(string $tool, int $status): self
    {
        return new self(\sprintf('Pamięć odpowiedziała kodem HTTP %d na narzędzie %s.', $status, $tool));
    }

    public static function malformed(string $tool, string $detail): self
    {
        return new self(\sprintf('Odpowiedź pamięci na %s jest niezrozumiała: %s', $tool, $detail));
    }

    public static function toolFailed(string $tool, string $message): self
    {
        return new self(\sprintf('Narzędzie %s zgłosiło błąd: %s', $tool, $message));
    }
}
