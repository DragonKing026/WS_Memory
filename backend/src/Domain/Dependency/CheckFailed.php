<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * We could not find out. Which is not the same as "nothing new".
 *
 * This exception exists to keep those two apart, because they look identical at
 * the point where the answer is `null` and they mean opposite things to whoever
 * reads the panel. "Brak nowszej wersji" is a reason to stop looking; "nie udało
 * się sprawdzić" is a reason to look again, and a version two releases behind
 * reported as up to date is the exact debt TODO-015 was written about.
 *
 * The message is Polish and phrased for a person, because it is stored in
 * `ws.dependency_state.check_problem` and shown verbatim to an administrator.
 * The cause travels in `previous` for the log; nothing here quotes a request
 * header, so the palace token cannot leak through a check that failed.
 */
final class CheckFailed extends \RuntimeException
{
    public static function catalogUnreachable(string $package, \Throwable $cause): self
    {
        return new self(
            \sprintf('Nie udało się odpytać PyPI o pakiet %s: %s', $package, $cause->getMessage()),
            previous: $cause,
        );
    }

    public static function catalogAnswered(string $package, int $status): self
    {
        return new self(\sprintf('PyPI odpowiedziało kodem HTTP %d na pytanie o pakiet %s.', $status, $package));
    }

    public static function catalogMalformed(string $package, string $detail): self
    {
        return new self(\sprintf('Odpowiedź PyPI o pakiecie %s jest niezrozumiała: %s', $package, $detail));
    }

    public static function serviceSilent(string $service, \Throwable $cause): self
    {
        return new self(
            \sprintf('Usługa %s nie podała swojej wersji: %s', $service, $cause->getMessage()),
            previous: $cause,
        );
    }

    public static function serviceMalformed(string $service, string $detail): self
    {
        return new self(\sprintf('Usługa %s podała wersję, której nie rozumiemy: %s', $service, $detail));
    }
}
