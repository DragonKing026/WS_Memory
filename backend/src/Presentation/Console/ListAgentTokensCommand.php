<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Entity\AgentToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What agent tokens an account has, so that `ws:agent:revoke` can be aimed.
 *
 * Revocation needs an identifier, and a token identifier exists in exactly two
 * places: the output of `ws:agent:token`, which scrolled away weeks ago, and the
 * panel, which needs somebody able to sign in — often the very thing being repaired.
 * So a shell that can revoke has to be a shell that can see what there is to revoke.
 *
 * **A separate command rather than a `--lista` flag on the revoking one.** One
 * command that reads with one flag and destroys without it is a typo away from
 * destroying when somebody meant to look, and this particular typo takes an agent
 * offline in the middle of its work. The same reason `rm` is not `ls -d`.
 *
 * Shows the same fields as `GET /api/agent-tokens` and never the token itself —
 * only its hash is stored, so there is nothing to show even here.
 */
#[AsCommand(
    name: 'ws:agent:list',
    description: 'Wypisuje tokeny agentów danego konta (bez samych tokenów)',
)]
final class ListAgentTokensCommand extends Command
{
    public function __construct(
        private readonly AccountLookup $accounts,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail właściciela tokenów');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');

        try {
            $owner = $this->accounts->byEmail($email);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        /** @var list<AgentToken> $tokens */
        $tokens = $this->entityManager->getRepository(AgentToken::class)
            ->findBy(['owner' => $owner], ['createdAt' => 'DESC']);

        if ([] === $tokens) {
            // Not an error: an account with no agents is the ordinary state of most
            // accounts, and a non-zero exit here would light up every script.
            $io->writeln(\sprintf('Konto %s nie ma żadnych tokenów agenta.', $owner->getEmail()));

            return Command::SUCCESS;
        }

        $io->section(\sprintf('Tokeny agentów konta %s', $owner->getEmail()));

        $io->table(
            ['Identyfikator', 'Etykieta', 'Zakres', 'Ostatnio użyty', 'Wygasa', 'Stan'],
            array_map(static fn (AgentToken $token): array => [
                $token->getId()->toRfc4122(),
                $token->getLabel(),
                null === $token->getSpaceScope()
                    ? 'wszystkie przestrzenie właściciela'
                    : (implode(', ', $token->getSpaceScope()) ?: 'żadna przestrzeń'),
                // The one field that makes a dead token identifiable. Without it
                // nobody dares retire anything and the list only ever grows.
                $token->getLastUsedAt()?->format('Y-m-d H:i') ?? 'nigdy',
                $token->getExpiresAt()?->format('Y-m-d H:i') ?? 'nigdy',
                self::stateOf($token),
            ], $tokens),
        );

        $io->writeln('Odwołanie: <info>ws:agent:revoke ' . $owner->getEmail() . ' <identyfikator></info>');

        return Command::SUCCESS;
    }

    /**
     * Three separate answers, because they call for three different actions:
     * a revoked token needs nothing, an expired one needs re-issuing, a working
     * one is what somebody came here to switch off.
     */
    private static function stateOf(AgentToken $token): string
    {
        if ($token->isRevoked()) {
            return 'odwołany ' . ($token->getRevokedAt()?->format('Y-m-d H:i') ?? '');
        }

        if ($token->isExpired()) {
            return 'wygasł';
        }

        return 'działa';
    }
}
