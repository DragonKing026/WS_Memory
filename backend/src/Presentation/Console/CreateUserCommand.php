<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Identity\PasswordPolicy;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Identity\AdministrationRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an account outright, from the server's shell.
 *
 * `ws:user:invite` prints a link somebody then has to open in a browser, and that
 * is right for inviting a colleague. It is wrong for the first account of a fresh
 * installation, which is what the installer is doing: at that moment there may be
 * no reachable web address yet, and the person running the script is the person who
 * will use the account. Two steps and a browser round trip in the middle is how the
 * installation ends with nobody knowing the password — which is exactly what
 * happened here once, and why this command exists (TODO-018).
 *
 * It goes through IssueInvitation and AcceptInvitation rather than around them, so
 * an account created here is identical to one created any other way: the same
 * refusals about existing addresses, the same private space, the same default
 * shared space, the same audit entries. A shortcut straight to `new User` would be
 * a second definition of what an account is, and the first thing it would forget is
 * the membership that makes the knowledge base usable on day one.
 *
 * **No mail is sent**, which is the one deviation and it is deliberate: the
 * invitation is accepted in the same breath, so the message would invite somebody
 * to create an account that already exists, using a link already spent.
 *
 * Generating the password is the default and `--password` the exception, for the
 * reason `ws:user:password` gives: a password passed as an argument is written into
 * the shell history of the machine it was typed on.
 */
#[AsCommand(
    name: 'ws:user:create',
    description: 'Zakłada konto od razu, bez przechodzenia przez link zaproszenia',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly IssueInvitation $issueInvitation,
        private readonly AcceptInvitation $acceptInvitation,
        private readonly PasswordPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail konta')
            ->addArgument('name', InputArgument::OPTIONAL, 'Nazwa widoczna w interfejsie (domyślnie część adresu przed @)')
            ->addOption(
                'admin',
                null,
                InputOption::VALUE_NONE,
                'Nadaj rolę administratora globalnego',
            )
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
        /** @var string|null $givenName */
        $givenName = $input->getArgument('name');
        /** @var string|null $given */
        $given = $input->getOption('password');
        $isAdmin = (bool) $input->getOption('admin');

        $password = $given ?? PasswordPolicy::generate();

        if (null !== $given) {
            $violations = $this->policy->violations($given);

            if ([] !== $violations) {
                $io->error($violations);
                $io->note('Bez opcji --password polecenie wygeneruje hasło, które tę regułę spełnia.');

                return Command::INVALID;
            }
        }

        try {
            // announce: false — patrz docblock. Zaproszenie jest przyjmowane
            // natychmiast, więc nie ma kogo zawiadamiać.
            $invitation = ($this->issueInvitation)($email, $isAdmin, null, announce: false);
            $user = ($this->acceptInvitation)($invitation->plainToken, $this->nameFrom($email, $givenName), $password);
        } catch (AdministrationRefused|\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Konto %s zostało założone%s.',
            $user->getEmail(),
            $isAdmin ? ' z rolą administratora globalnego' : '',
        ));

        if (null === $given) {
            $io->writeln('Hasło:');
            $io->writeln('  ' . $password);
            $io->newLine();
            $io->warning('To hasło widzisz jeden raz — w bazie jest wyłącznie jego skrót.');
        } else {
            $io->note('Podane hasło zostało w historii powłoki tej maszyny. Wyczyść ją albo zmień hasło poleceniem ws:user:password.');
        }

        return Command::SUCCESS;
    }

    /**
     * The part before the `@` when nobody said otherwise.
     *
     * A display name is required by the account and asking for it would be one more
     * question in a script whose whole point is asking as few as possible. The
     * person can change it later; an empty name they cannot.
     */
    private function nameFrom(string $email, ?string $given): string
    {
        if (null !== $given && '' !== trim($given)) {
            return trim($given);
        }

        $local = strstr(trim($email), '@', true);

        return false === $local || '' === $local ? trim($email) : $local;
    }
}
