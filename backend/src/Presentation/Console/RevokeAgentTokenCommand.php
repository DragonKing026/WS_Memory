<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\AgentToken\RevokeAgentToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retires an agent token from the shell.
 *
 * `ws:agent:token` could hand out credentials and nothing could take them back
 * without a browser session, so a token issued for one evening's work on the console
 * was being revoked with an UPDATE against the database — a write that skips the
 * application: no audit entry, no "only your own" rule, and a `revoked_at` that
 * whoever typed it had to format correctly.
 *
 * So this calls RevokeAgentToken, the same use case `DELETE /api/agent-tokens/{id}`
 * calls. Two consequences are inherited rather than reimplemented, and both are the
 * point of not writing a second path:
 *
 *   - the token must belong to the account named as the owner, and somebody else's
 *     token answers exactly like one that does not exist — otherwise a shell could
 *     enumerate credentials by identifier;
 *   - revoking twice succeeds and keeps the first moment, so a repeated command does
 *     not rewrite when access actually ended.
 *
 * The owner's address is an argument rather than something inferred, because a
 * console has no signed-in user and "whose token is this" is not a question this
 * command may answer on its own.
 */
#[AsCommand(
    name: 'ws:agent:revoke',
    description: 'Odwołuje token agenta AI (natychmiast i nieodwracalnie)',
)]
final class RevokeAgentTokenCommand extends Command
{
    public function __construct(
        private readonly AccountLookup $accounts,
        private readonly RevokeAgentToken $revokeAgentToken,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail właściciela tokena')
            ->addArgument('token', InputArgument::REQUIRED, 'Identyfikator tokena z ws:agent:list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $tokenId */
        $tokenId = $input->getArgument('token');

        try {
            $owner = $this->accounts->byEmail($email);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!($this->revokeAgentToken)($owner, $tokenId)) {
            // One answer for "no such token", "not a valid identifier" and "somebody
            // else's token", exactly like the endpoint. The hint below is printed in
            // all three cases, so it says nothing about which one happened.
            $io->error('Nie ma takiego tokena.');
            $io->note(
                'Token należący do innego konta odpowiada tak samo jak nieistniejący. '
                . 'Sprawdź listę: ws:agent:list ' . $owner->getEmail()
            );

            return Command::FAILURE;
        }

        $io->success(\sprintf('Token %s został odwołany.', $tokenId));

        // Worth saying, because the opposite is true of the password command next to
        // it: an agent token is checked against the database on every single call,
        // so revocation takes effect on the agent's next request with no waiting.
        $io->writeln('Agent dostanie odmowę przy najbliższym wywołaniu — token sprawdzany jest przy każdym żądaniu.');

        return Command::SUCCESS;
    }
}
