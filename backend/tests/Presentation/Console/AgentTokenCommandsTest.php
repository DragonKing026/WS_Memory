<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Console;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\AgentToken\IssuedAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `ws:agent:list` and `ws:agent:revoke` — retiring a machine credential from a shell.
 *
 * Revocation is asserted against `/mcp`, not against the `revoked_at` column. The
 * column is what the command writes; whether the agent is actually turned away is a
 * different sentence, and it is the one somebody typing this command is making.
 *
 * The test that matters most is the one about somebody else's token. The use case
 * answers the same way for "does not exist" and "belongs to another account", because
 * a shell that could tell them apart would be a way to enumerate other people's
 * credentials by identifier. Asserting the two outputs are identical pins that, and a
 * count of what was revoked pins the other half: nothing was.
 */
final class AgentTokenCommandsTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';
    private const OWNER = 'wlasciciel@web-systems.pl';
    private const STRANGER = 'obcy@web-systems.pl';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        $this->owner = ($accept)(($issue)(self::OWNER)->plainToken, 'Właściciel', self::PASSWORD);
        ($accept)(($issue)(self::STRANGER)->plainToken, 'Obcy', self::PASSWORD);
    }

    public function testRevokedTokenIsRefusedOnTheNextCall(): void
    {
        $issued = $this->issueToken('jednorazowa robota');

        self::assertTrue($this->works($issued->plainToken), 'a fresh token has to work, or this test proves nothing');

        $tester = $this->invoke('ws:agent:revoke', ['email' => self::OWNER, 'token' => $issued->tokenId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFalse(
            $this->works($issued->plainToken),
            'revocation from the console must take the agent out of service, not just write a timestamp',
        );
    }

    public function testSomebodyElsesTokenIsNeitherRevokedNorAcknowledged(): void
    {
        $issued = $this->issueToken('cudzy');

        $onSomebodyElses = $this->invoke('ws:agent:revoke', [
            'email' => self::STRANGER,
            'token' => $issued->tokenId,
        ]);
        $onNothingAtAll = $this->invoke('ws:agent:revoke', [
            'email' => self::STRANGER,
            // A well-formed identifier of a token that was never issued.
            'token' => '0191b8d2-0000-7000-8000-000000000000',
        ]);

        self::assertNotSame(Command::SUCCESS, $onSomebodyElses->getStatusCode());
        self::assertSame(
            $onNothingAtAll->getDisplay(),
            $onSomebodyElses->getDisplay(),
            'a token belonging to somebody else must read exactly like one that does not exist',
        );

        self::assertNull(
            $this->connection->fetchOne(
                'SELECT revoked_at FROM ws.agent_tokens WHERE id = :id',
                ['id' => $issued->tokenId],
            ),
            'the owner of this token is still working with it',
        );
        self::assertTrue($this->works($issued->plainToken));
    }

    public function testListingNamesTheTokensAndTheirStateWithoutShowingTheTokens(): void
    {
        $working = $this->issueToken('laptop');
        $retired = $this->issueToken('stary serwer');
        $this->invoke('ws:agent:revoke', ['email' => self::OWNER, 'token' => $retired->tokenId]);

        $tester = $this->invoke('ws:agent:list', ['email' => self::OWNER]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        // The identifier is the whole reason this command exists: it is the argument
        // ws:agent:revoke needs, and nothing else prints it once the issuing output
        // has scrolled away.
        self::assertStringContainsString($working->tokenId, $display);
        self::assertStringContainsString('laptop', $display);
        self::assertStringContainsString($retired->tokenId, $display);
        self::assertStringContainsString('odwołany', $display);
        self::assertStringContainsString('działa', $display);

        self::assertStringNotContainsString(
            $working->plainToken,
            $display,
            'only the hash is stored, so there is nothing to print — and nothing to leak into a terminal log',
        );
    }

    public function testListingSaysSoWhenAnAccountHasNoAgents(): void
    {
        $tester = $this->invoke('ws:agent:list', ['email' => self::STRANGER]);

        // Not a failure: most accounts have no agents, and a non-zero exit for the
        // ordinary case is how a script learns to ignore this command's exit code.
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('nie ma żadnych tokenów', $tester->getDisplay());
    }

    public function testBothCommandsFailOnAnAccountThatDoesNotExist(): void
    {
        foreach (['ws:agent:list' => [], 'ws:agent:revoke' => ['token' => 'cokolwiek']] as $command => $extra) {
            $tester = $this->invoke($command, ['email' => 'nie-ma-takiego@web-systems.pl'] + $extra);

            self::assertNotSame(Command::SUCCESS, $tester->getStatusCode(), $command);
            self::assertStringContainsString('Nie ma konta', $tester->getDisplay(), $command);
        }
    }

    private function issueToken(string $label): IssuedAgentToken
    {
        return (static::getContainer()->get(IssueAgentToken::class))($this->owner, $label);
    }

    /**
     * Whether the gateway still lets this token in.
     *
     * `ping` on purpose: it touches no memory, so this says something about the
     * credential and nothing about whether a palace is running.
     */
    private function works(string $plainToken): bool
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $plainToken,
            ],
            content: json_encode(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        return $this->client->getResponse()->isSuccessful();
    }

    /**
     * @param array<string, string> $arguments
     */
    private function invoke(string $command, array $arguments): CommandTester
    {
        // Built per call: the browser reboots the kernel between requests, and an
        // Application holding commands from the container before the reboot would run
        // them against a closed one.
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $console = new Application($kernel);
        $console->setAutoExit(false);

        $tester = new CommandTester($console->find($command));
        $tester->execute($arguments);

        return $tester;
    }
}
