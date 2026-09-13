<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Console;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `ws:user:create` — the first account of an installation, in one step.
 *
 * Checked through `/api/login`, like the password command: the claim is not "a row
 * was written" but "this account can be used", and only the second one matters to
 * somebody standing in front of a fresh installation.
 *
 * Two of these tests are about things the command must NOT do.
 *
 *   - **it must not be a second definition of what an account is.** An account
 *     created here has the private space and the default shared space that every
 *     other account gets, because the command goes through the same two use cases
 *     rather than around them. A shortcut to `new User` would pass a "the account
 *     exists" test and leave somebody belonging to nothing;
 *   - **it must not send mail.** The invitation is accepted in the same breath, so
 *     a message would invite somebody to create an account that already exists with
 *     a link already spent — and on an installation with no SMTP configured it would
 *     put a failed row in the mail journal as the first thing anybody sees there.
 */
final class UserCreateCommandTest extends WebTestCase
{
    private const EMAIL = 'pierwszy@web-systems.pl';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.messenger_messages, ws.mail_log, ws.agent_tokens, ws.space_members, '
            . 'ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );
    }

    public function testItCreatesAnAccountThatCanSignInWithThePrintedPassword(): void
    {
        $tester = $this->invoke(['email' => self::EMAIL, '--admin' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->canSignIn($this->printedPassword($tester)));
    }

    public function testTheAdminFlagIsWhatDecidesTheRole(): void
    {
        $this->invoke(['email' => self::EMAIL, '--admin' => true]);
        $this->invoke(['email' => 'zwykly@web-systems.pl']);

        self::assertStringContainsString('ROLE_ADMIN', (string) $this->rolesOf(self::EMAIL));
        self::assertStringNotContainsString('ROLE_ADMIN', (string) $this->rolesOf('zwykly@web-systems.pl'));
    }

    /**
     * The account is the same kind of account as any other.
     *
     * Two memberships: the private space every account gets on accepting an
     * invitation, and the shared one from D-035. A command that wrote a user row
     * directly would satisfy "the account exists" and leave its owner looking at
     * "nie należysz jeszcze do żadnej przestrzeni" — which is the bug D-035 was
     * written to end.
     */
    public function testTheAccountBelongsToItsPrivateSpaceAndToTheSharedOne(): void
    {
        $this->invoke(['email' => self::EMAIL]);

        $spaces = $this->connection->fetchFirstColumn(
            'SELECT s.slug FROM ws.space_members m JOIN ws.spaces s ON s.id = m.space_id '
            . 'JOIN ws.users u ON u.id = m.user_id WHERE u.email = :email ORDER BY s.slug',
            ['email' => self::EMAIL],
        );

        self::assertCount(2, $spaces, 'konto ma przestrzeń prywatną i wspólną');
        self::assertContains('wspolna', $spaces);
    }

    public function testItSendsNoMail(): void
    {
        $this->invoke(['email' => self::EMAIL]);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT count(*) FROM ws.mail_log'),
            'zaproszenie przyjęte w tej samej chwili nie ma kogo zawiadamiać',
        );
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT count(*) FROM ws.messenger_messages'),
            'a skoro nie ma maila, nie ma też wiadomości w kolejce',
        );
    }

    /**
     * `ws:user:invite` still announces. The flag is for this command, not a change
     * to how invitations work.
     */
    public function testInvitingStillSendsMail(): void
    {
        $this->invoke(['email' => self::EMAIL, '--admin' => true]);

        $console = $this->console();
        $tester = new CommandTester($console->find('ws:user:invite'));
        $tester->execute(['email' => 'zapraszany@web-systems.pl']);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT count(*) FROM ws.mail_log WHERE recipient = :email',
                ['email' => 'zapraszany@web-systems.pl'],
            ),
        );
    }

    public function testAnExistingAddressIsRefusedRatherThanDuplicated(): void
    {
        $this->invoke(['email' => self::EMAIL]);
        $tester = $this->invoke(['email' => self::EMAIL]);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM ws.users'));
    }

    public function testAPasswordGivenOnTheCommandLineMustSatisfyTheSameRule(): void
    {
        $tester = $this->invoke(['email' => self::EMAIL, '--password' => 'Krotkie1!']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('12 znaków', $tester->getDisplay());
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT count(*) FROM ws.users'),
            'odrzucone hasło nie może zostawić po sobie konta',
        );
    }

    public function testTheDisplayNameDefaultsToThePartBeforeTheAt(): void
    {
        $this->invoke(['email' => self::EMAIL]);

        self::assertSame(
            'pierwszy',
            $this->connection->fetchOne(
                'SELECT display_name FROM ws.users WHERE email = :email',
                ['email' => self::EMAIL],
            ),
        );
    }

    public function testAGivenNameIsUsed(): void
    {
        $this->invoke(['email' => self::EMAIL, 'name' => 'Artur Ograbek']);

        self::assertSame(
            'Artur Ograbek',
            $this->connection->fetchOne(
                'SELECT display_name FROM ws.users WHERE email = :email',
                ['email' => self::EMAIL],
            ),
        );
    }

    // ------------------------------------------------------------------ narzędzia

    private function printedPassword(CommandTester $tester): string
    {
        $pattern = '/Hasło:\s+(\S+)/u';

        self::assertMatchesRegularExpression(
            $pattern,
            $tester->getDisplay(),
            'hasło pokazywane jest raz — jeśli nie ma go na wyjściu, przepadło',
        );

        preg_match($pattern, $tester->getDisplay(), $matches);

        return (string) ($matches[1] ?? '');
    }

    private function rolesOf(string $email): mixed
    {
        return $this->connection->fetchOne('SELECT roles FROM ws.users WHERE email = :email', ['email' => $email]);
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
     * @param array<string, mixed> $arguments
     */
    private function invoke(array $arguments): CommandTester
    {
        $tester = new CommandTester($this->console()->find('ws:user:create'));
        $tester->execute($arguments);

        return $tester;
    }

    private function console(): Application
    {
        // Zbudowana przy każdym wywołaniu, nie raz w setUp: przeglądarka restartuje
        // jądro między żądaniami, a Application trzymająca polecenia z poprzedniego
        // kontenera uruchamiałaby je na zamkniętym.
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $console = new Application($kernel);
        $console->setAutoExit(false);

        return $console;
    }
}
