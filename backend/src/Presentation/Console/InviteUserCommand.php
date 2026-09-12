<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Invitation\IssueInvitation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues an invitation from the command line.
 *
 * This is how the first account comes into existence — there is no other way in
 * before somebody can sign in, and open registration is deliberately absent.
 *
 * The link is printed rather than only e-mailed, because the very first
 * invitation is typically issued before the mailer is configured, and an
 * administrator locked out of their own fresh installation is a poor start.
 */
#[AsCommand(
    name: 'ws:user:invite',
    description: 'Wystawia zaproszenie do WS_Memory i wypisuje link',
)]
final class InviteUserCommand extends Command
{
    public function __construct(
        private readonly IssueInvitation $issueInvitation,
        private readonly string $publicBaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail zapraszanej osoby')
            ->addOption(
                'admin',
                null,
                InputOption::VALUE_NONE,
                'Nadaj rolę administratora globalnego (zarządzanie kontami i przestrzeniami)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $isAdmin = (bool) $input->getOption('admin');

        try {
            $invitation = ($this->issueInvitation)($email, $isAdmin);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success("Zaproszenie dla {$invitation->email} wystawione.");
        $io->writeln('Link (ważny do ' . $invitation->expiresAt->format('Y-m-d H:i') . '):');
        $io->writeln('  ' . $invitation->acceptUrl($this->publicBaseUrl));
        $io->newLine();

        // Said plainly, because the token exists in this output and nowhere
        // else: the database holds only its hash.
        $io->note('Link pokazywany jest raz. W bazie jest wyłącznie jego skrót.');

        if ($isAdmin) {
            $io->warning(
                'Konto dostanie rolę administratora globalnego: zarządzanie kontami, '
                . 'przestrzeniami i rolami. Nie daje wglądu w treść cudzych przestrzeni (D-016).'
            );
        }

        return Command::SUCCESS;
    }
}
