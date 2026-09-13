<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Console;

use App\Application\Identity\PasswordPolicy;
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
 * `ws:user:password` — the way back into an installation nobody can sign into.
 *
 * Checked through `/api/login` rather than by reading the hash column, because the
 * claim being made is not "a hash was written" but "this account can be used again".
 * A hash written with the wrong algorithm, or against a stale entity, satisfies the
 * first and fails the second — and the second is the only one anybody cares about at
 * three in the morning.
 *
 * The audit assertion is about an absence: the entry must name **no** actor. Nobody
 * is signed in on a console, so any actor in that row would be a fabrication — the
 * account itself would read as "they reset their own password", which is exactly what
 * did not happen.
 */
final class UserPasswordCommandTest extends WebTestCase
{
    private const EMAIL = 'zapomnial@web-systems.pl';
    private const OLD_PASSWORD = 'StareDlugieHaslo1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

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
        ($accept)(($issue)(self::EMAIL)->plainToken, 'Zapomniał Hasła', self::OLD_PASSWORD);
    }

    public function testGeneratedPasswordSignsTheAccountInAndTheOldOneStopsWorking(): void
    {
        $tester = $this->invoke('ws:user:password', ['email' => self::EMAIL]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $password = $this->printedPassword($tester);
        self::assertGreaterThanOrEqual(
            PasswordPolicy::MINIMUM_LENGTH,
            mb_strlen($password),
            'a generated password that the policy would refuse is worse than useless',
        );

        self::assertTrue($this->canSignIn($password), 'the printed password must be the one that works');
        self::assertFalse($this->canSignIn(self::OLD_PASSWORD), 'the previous password must stop working');
    }

    /**
     * The one thing the output has to say that the operator cannot see for themselves.
     *
     * "Reset hasła" reads as "odcięcie dostępu", and here it is not: JWT is stateless
     * (D-017), so every token already issued keeps working until it expires. Somebody
     * who believes otherwise stops looking for the session they meant to end.
     */
    public function testTheOutputSaysExistingTokensKeepWorking(): void
    {
        $display = $this->invoke('ws:user:password', ['email' => self::EMAIL])->getDisplay();

        self::assertStringContainsString('bezstanowe', $display);
        self::assertStringContainsString('wygaśnięcia', $display);
    }

    public function testUnknownAccountFailsInsteadOfCreatingOne(): void
    {
        $tester = $this->invoke('ws:user:password', ['email' => 'nie-ma-takiego@web-systems.pl']);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nie ma konta', $tester->getDisplay());
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM ws.users'),
            'a command that sets passwords must never be a command that creates accounts',
        );
    }

    public function testTheAuditEntryNamesNoActor(): void
    {
        $this->invoke('ws:user:password', ['email' => self::EMAIL]);

        $row = $this->connection->fetchAssociative(
            'SELECT actor_user_id, actor_agent_token_id, target FROM ws.audit_log '
            . "WHERE action = 'user.password_reset'",
        );

        self::assertNotFalse($row, 'a password set outside the audit trail is a password nobody can account for');
        self::assertNull($row['actor_user_id'], 'nobody is signed in on a console — naming an actor would be a fiction');
        self::assertNull($row['actor_agent_token_id']);
        self::assertStringContainsString(self::EMAIL, (string) $row['target']);
    }

    /**
     * The policy is one rule, not two copies of a rule.
     *
     * `POST /api/invitations/accept` refuses a password shorter than twelve characters;
     * so does this. The moment the console had its own idea of what is long enough,
     * the shorter of the two would be the real policy of the installation.
     */
    public function testAPasswordGivenOnTheCommandLineMustSatisfyTheSameRuleAsTheApi(): void
    {
        $tester = $this->invoke('ws:user:password', [
            'email' => self::EMAIL,
            '--haslo' => 'Krotkie1!',
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('12 znaków', $tester->getDisplay());
        self::assertTrue(
            $this->canSignIn(self::OLD_PASSWORD),
            'a refused password must leave the account exactly as it was',
        );
    }

    public function testAPasswordGivenOnTheCommandLineIsAcceptedWhenItSatisfiesTheRule(): void
    {
        $tester = $this->invoke('ws:user:password', [
            'email' => self::EMAIL,
            '--haslo' => 'ZupelnieNoweDlugieHaslo1',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString(
            'ZupelnieNoweDlugieHaslo1',
            $tester->getDisplay(),
            'echoing back a password the operator already typed only puts it in one more place',
        );
        self::assertTrue($this->canSignIn('ZupelnieNoweDlugieHaslo1'));
    }

    /**
     * A deactivated account gets the password — and is told that will not be enough.
     *
     * Refusing would be the wrong answer: the password is half of getting somebody
     * back, and it changes nothing about who may sign in, so the audit entry cannot
     * overstate what happened. That is the difference from granting a role to a
     * deactivated account, which IS refused — there the entry would claim access that
     * nobody has.
     */
    public function testDeactivatedAccountGetsThePasswordAndIsSaidToBeSwitchedOff(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $user->deactivate();
        $this->em->flush();

        $tester = $this->invoke('ws:user:password', ['email' => self::EMAIL]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('WYŁĄCZONE', $tester->getDisplay());
        self::assertFalse(
            $this->canSignIn($this->printedPassword($tester)),
            'the point of the warning: a switched-off account does not sign in, password or not',
        );
        /** @var array<string, mixed> $target */
        $target = json_decode(
            (string) $this->connection->fetchOne(
                "SELECT target FROM ws.audit_log WHERE action = 'user.password_reset'",
            ),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertFalse(
            $target['account_active'],
            'the audit entry has to say whether the account could be used with this password',
        );
    }

    private function printedPassword(CommandTester $tester): string
    {
        $display = $tester->getDisplay();
        $pattern = '/Nowe hasło:\s+(\S+)/u';

        self::assertMatchesRegularExpression(
            $pattern,
            $display,
            'the generated password is shown once — if it is not in the output it is lost',
        );

        preg_match($pattern, $display, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function canSignIn(string $password): bool
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => self::EMAIL, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse()->isSuccessful();
    }

    /**
     * @param array<string, string> $arguments
     */
    private function invoke(string $command, array $arguments): CommandTester
    {
        // Built per call rather than once in setUp: the browser reboots the kernel
        // between requests, and an Application holding on to commands from the
        // container before the reboot would run them against a closed one.
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $console = new Application($kernel);
        $console->setAutoExit(false);

        $tester = new CommandTester($console->find($command));
        $tester->execute($arguments);

        return $tester;
    }
}
