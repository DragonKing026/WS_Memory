<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Nothing was changed, and here is the sentence to show the person who asked.
 *
 * One exception carrying a reason rather than nine classes, for the reason spelled
 * out on UpdateRefused: every caller does the same two things with it — turn the
 * reason into a status code, print the message — and nine classes would mean nine
 * `catch` blocks that all have to stay in step.
 *
 * It extends \DomainException on purpose. `ws:user:invite` already catches that and
 * prints the message, so the console keeps working unchanged while the REST surface
 * reads the reason and answers with a code.
 *
 * The messages are Polish, written for a person, and each one says what to do next.
 * "Konflikt" tells an administrator nothing; "poproś innego administratora"
 * tells them where to go. They quote nothing but an e-mail address — these are
 * produced on a path any signed-in administrator can reach, and a message carrying
 * a token or a hash would put it on a screen and into a log at once.
 */
final class AdministrationRefused extends \DomainException
{
    private function __construct(
        public readonly AdministrationRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownUser(): self
    {
        return new self(
            AdministrationRefusal::UnknownUser,
            'Nie ma takiego konta.',
        );
    }

    /**
     * You cannot take the global role off yourself.
     *
     * The screen that offers the switch is behind the role, so the click that
     * removes it also removes the way back to the switch. With a colleague still
     * holding the role this is recoverable by asking them, which is what the
     * message says; when nobody else holds it, LastAdministrator answers first
     * because then it is not recoverable at all.
     */
    public static function selfDemotion(): self
    {
        return new self(
            AdministrationRefusal::SelfDemotion,
            'Nie można odebrać sobie roli administratora — ekran, na którym się ją nadaje, '
            . 'jest za tą rolą. Poproś innego administratora.',
        );
    }

    public static function lastAdministrator(): self
    {
        return new self(
            AdministrationRefusal::LastAdministrator,
            'To jedyne aktywne konto administratora. Instalacja bez administratora nie ma jak '
            . 'wystawić zaproszenia ani nadać roli, więc tej roli nie da się odebrać. '
            . 'Najpierw nadaj rolę komuś jeszcze.',
        );
    }

    public static function selfDeactivation(): self
    {
        return new self(
            AdministrationRefusal::SelfDeactivation,
            'Nie można wyłączyć własnego konta — po wylogowaniu nie byłoby jak go włączyć. '
            . 'Poproś innego administratora.',
        );
    }

    public static function malformedEmail(string $email): self
    {
        return new self(
            AdministrationRefusal::MalformedEmail,
            \sprintf('„%s” nie wygląda na adres e-mail. Zaproszenie trafia na adres, więc musi być poprawny.', $email),
        );
    }

    public static function accountExists(string $email): self
    {
        return new self(
            AdministrationRefusal::AccountExists,
            \sprintf('Konto %s już istnieje. Zaproszenia nie wystawiono.', $email),
        );
    }

    /**
     * A second invitation to the same address would be a second working token to
     * one account, and only one of them can ever be used — so whoever pasted the
     * older link would be told their invitation is invalid without being able to
     * tell why.
     */
    public static function invitationPending(string $email): self
    {
        return new self(
            AdministrationRefusal::InvitationPending,
            \sprintf(
                'Na adres %s jest już wystawione zaproszenie, które nie wygasło i nie zostało przyjęte. '
                . 'Unieważnij je, jeśli chcesz wystawić nowe.',
                $email,
            ),
        );
    }

    public static function unknownInvitation(): self
    {
        return new self(
            AdministrationRefusal::UnknownInvitation,
            'Nie ma takiego zaproszenia.',
        );
    }

    public static function invitationAccepted(string $email): self
    {
        return new self(
            AdministrationRefusal::InvitationAccepted,
            \sprintf(
                'Zaproszenie zostało już przyjęte — konto %s istnieje. Unieważnienie zaproszenia nic tu nie zmieni; '
                . 'żeby odciąć dostęp, wyłącz konto.',
                $email,
            ),
        );
    }
}
