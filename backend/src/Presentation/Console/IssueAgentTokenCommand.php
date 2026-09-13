<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\AgentToken\IssueAgentToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues an agent token from the command line.
 *
 * The screens for this arrive with the frontend (TODO-008), and until then this is
 * the only way to connect an agent at all — the same reason `ws:user:invite`
 * exists. It prints the ready `claude mcp add` command, because the alternative is
 * everybody reconstructing it from the documentation and getting the header wrong.
 */
#[AsCommand(
    name: 'ws:agent:token',
    description: 'Wystawia token agenta AI i wypisuje polecenie podłączenia',
)]
final class IssueAgentTokenCommand extends Command
{
    public function __construct(
        private readonly IssueAgentToken $issueAgentToken,
        private readonly AccountLookup $accounts,
        private readonly string $publicBaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail właściciela tokena')
            ->addArgument('label', InputArgument::REQUIRED, 'Etykieta, po której poznasz token później')
            ->addOption(
                'space',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Zawęź token do wskazanej przestrzeni (można podać wiele razy). '
                . 'Bez tej opcji token widzi wszystko, co widzi właściciel.',
            )
            ->addOption(
                'expires',
                null,
                InputOption::VALUE_REQUIRED,
                'Data wygaśnięcia, na przykład 2027-01-01. Bez niej token nie wygasa.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $label */
        $label = $input->getArgument('label');
        /** @var list<string> $spaces */
        $spaces = $input->getOption('space');
        /** @var string|null $expires */
        $expires = $input->getOption('expires');

        try {
            $owner = $this->accounts->byEmail($email);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $expiresAt = null;
        if (null !== $expires) {
            try {
                $expiresAt = new \DateTimeImmutable($expires);
            } catch (\Exception) {
                $io->error(\sprintf('„%s" nie jest datą.', $expires));

                return Command::FAILURE;
            }
        }

        try {
            $issued = ($this->issueAgentToken)($owner, $label, [] === $spaces ? null : $spaces, $expiresAt);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Token „%s" wystawiony dla %s.', $issued->label, $owner->getEmail()));

        $io->definitionList(
            ['Identyfikator' => $issued->tokenId],
            ['Zakres' => null === $issued->spaceScope ? 'wszystkie przestrzenie właściciela' : implode(', ', $issued->spaceScope)],
            ['Wygasa' => $issued->expiresAt?->format('Y-m-d H:i') ?? 'nigdy'],
        );

        $io->section('Podłączenie agenta');
        $io->writeln($issued->claudeCodeCommand($this->publicBaseUrl));
        $io->newLine();
        $io->warning('Ten token widzisz jeden raz. Nie da się go odczytać ponownie — w bazie jest tylko skrót.');

        return Command::SUCCESS;
    }
}
