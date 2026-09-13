<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Identity\PasswordPolicy;
use App\Application\Identity\ResetUserPassword;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sets a new password on an existing account, from the server's shell.
 *
 * This is how an installation is recovered. Without it, an administrator who had
 * lost their password could only be replaced: a new invitation to a different
 * address, and the original account left locked next to the new one. Every
 * password-reset-by-e-mail flow depends on the mailer working and on somebody still
 * reading that mailbox, and the case this command is for is the one where neither is
 * true.
 *
 * **Generating is the default and `--password` is the exception**, which is the opposite
 * of what a convenience flag usually looks like. A password passed as an argument is
 * written into the shell history of the machine it was typed on, and a recovery
 * password in `~/.bash_history` outlives every reason it was set.
 */
#[AsCommand(
    name: 'ws:user:password',
    description: 'Ustawia nowe hasło istniejącemu kontu (domyślnie generuje je i wypisuje raz)',
)]
final class SetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly AccountLookup $accounts,
        private readonly PasswordPolicy $policy,
        private readonly ResetUserPassword $resetPassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail konta')
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Ustaw podane hasło zamiast wygenerowanego. Uwaga: zostaje w historii powłoki.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string|null $given */
        $given = $input->getOption('password');

        try {
            $user = $this->accounts->byEmail($email);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $password = $given ?? PasswordPolicy::generate();

        if (null !== $given) {
            $violations = $this->policy->violations($given);

            if ([] !== $violations) {
                // Exit 2, like every other "you called me wrongly": the account was
                // not touched, and a script that branches on the code should be able
                // to tell that apart from "there is no such account".
                $io->error($violations);
                $io->note('Bez opcji --password polecenie wygeneruje hasło, które tę regułę spełnia.');

                return Command::INVALID;
            }
        }

        ($this->resetPassword)($user, $password);

        $io->success(\sprintf('Hasło konta %s zostało ustawione.', $user->getEmail()));

        if (null === $given) {
            $io->writeln('Nowe hasło:');
            $io->writeln('  ' . $password);
            $io->newLine();
            $io->warning('To hasło widzisz jeden raz — w bazie jest wyłącznie jego skrót.');
        } else {
            $io->note('Podane hasło zostało w historii powłoki tej maszyny. Wyczyść ją albo zmień hasło na wygenerowane.');
        }

        // Said out loud, because "reset hasła" sounds like "odcięcie dostępu" and
        // here it is not. JWT is stateless (D-017): the backend does not keep a list
        // of issued tokens, so there is nothing to invalidate. Whoever is signed in
        // stays signed in until their token expires, and the account's agent tokens
        // are separate credentials that a password never touched.
        $io->warning(
            "Zmiana hasła NIE wylogowuje nikogo: wydane tokeny JWT są bezstanowe i działają do wygaśnięcia.\n"
            . 'Tokeny agentów tego konta też działają dalej — odwołaj je przez ws:agent:revoke. '
            . 'Żeby odciąć dostęp natychmiast, wyłącz konto (to odwołuje też wszystkie jego tokeny agentów).'
        );

        if (!$user->isActive()) {
            $io->warning(
                'Konto jest WYŁĄCZONE, więc samo hasło nie wystarczy do zalogowania. '
                . 'Włącz je w panelu administratora (POST /api/admin/users/{id}/aktywnosc) — hasło jest już ustawione.'
            );
        }

        return Command::SUCCESS;
    }
}
