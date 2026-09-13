<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * The order was not written, and here is the sentence to show the person who asked.
 *
 * One exception with a reason on it rather than five classes, because every caller
 * does the same two things with it — turn the reason into a status code, print the
 * message — and five classes would mean five `catch` blocks that must all be kept in
 * step. The reason is an enum so the mapping to HTTP lives in the one place that
 * knows about HTTP, and the domain stays unaware of status codes.
 *
 * The messages are Polish and written for a person, because they are shown verbatim
 * in the panel, and each one says what to DO. "Konflikt" tells a reader nothing;
 * "poczekaj, aż poprzednia aktualizacja się zakończy" tells them whether to come
 * back in a minute or to go and look at the host.
 *
 * They also never quote anything but the dependency name and a version number.
 * The refusals are produced on a path reachable by any logged-in administrator, and
 * an exception message that carried a connection string or a token would put it on
 * a screen and in a log at once.
 */
final class UpdateRefused extends \RuntimeException
{
    private function __construct(
        public readonly UpdateRefusal $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function unknownDependency(string $name): self
    {
        return new self(
            UpdateRefusal::UnknownDependency,
            \sprintf('Nie znamy zależności „%s”, więc nie ma czego aktualizować.', $name),
        );
    }

    /**
     * The target does not look like a version at all.
     *
     * This is the boundary the whole design rests on: the number travels to `.env`
     * on the host and from there into `pip install mempalace==<version>`. The
     * pattern is narrow on purpose — three groups of digits and two dots, nothing
     * else — so there is nothing in the accepted set to escape out of.
     */
    public static function malformedTarget(string $target): self
    {
        return new self(
            UpdateRefusal::MalformedTarget,
            \sprintf(
                'Numer wersji „%s” nie ma postaci X.Y.Z. Podaj trzy liczby rozdzielone kropkami, na przykład 3.9.0.',
                $target,
            ),
        );
    }

    public static function targetNotInCatalog(string $package, Version $target): self
    {
        return new self(
            UpdateRefusal::TargetNotInCatalog,
            \sprintf(
                'Wydania %s pakietu %s nie ma wśród wersji, które znamy z PyPI. '
                . 'Naciśnij „Sprawdź teraz” i zleć aktualizację do wersji, którą panel pokaże jako najnowszą.',
                $target,
                $package,
            ),
        );
    }

    /**
     * We could not confirm the target exists, which is not the same as knowing it
     * does not — and on this path the difference does not help the caller, because
     * either way we refuse to write an order for a version nobody vouched for.
     */
    public static function targetUnconfirmed(Version $target, string $why): self
    {
        return new self(
            UpdateRefusal::TargetNotInCatalog,
            \sprintf(
                'Nie udało się potwierdzić w PyPI, że wersja %s istnieje (%s). '
                . 'Zlecenia nie zapisano — spróbuj ponownie, gdy PyPI będzie osiągalne.',
                $target,
                $why,
            ),
        );
    }

    /**
     * @param \Throwable|null $previous the database's own complaint, when the conflict
     *                                  was caught by the partial unique index rather
     *                                  than by the check that precedes it. Carried for
     *                                  the log; the reader sees the same sentence
     *                                  either way, because the two cases differ only
     *                                  in how close the other click was
     */
    public static function alreadyInFlight(string $name, ?\Throwable $previous = null): self
    {
        return new self(
            UpdateRefusal::AlreadyInFlight,
            \sprintf(
                'Aktualizacja zależności %s jest już zlecona albo właśnie trwa. '
                . 'Poczekaj, aż się zakończy — jej dziennik pojawi się w panelu.',
                $name,
            ),
            $previous,
        );
    }

    /**
     * Nobody is listening, so nothing would happen.
     *
     * Refusing here is not pedantry about health checks. An order written with no
     * agent to take it stays `pending`, the partial unique index counts it as in
     * flight, and every later order for that dependency is refused — so one click
     * against a dead agent would disable the feature until somebody edited the
     * table by hand.
     */
    public static function updaterSilent(int $freshForSeconds): self
    {
        return new self(
            UpdateRefusal::UpdaterSilent,
            \sprintf(
                'Agent aktualizacji na hoście nie zgłosił się od ponad %d minut, więc nikt nie wykonałby tego zlecenia. '
                . 'Sprawdź na hoście: systemctl status ws-memory-aktualizator.timer',
                intdiv($freshForSeconds, 60),
            ),
        );
    }
}
