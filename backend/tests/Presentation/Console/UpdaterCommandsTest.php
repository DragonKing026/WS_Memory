<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Console;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The three commands the host-side agent calls, tested as a shell sees them.
 *
 * `scripts/aktualizator.sh` parses this output with `jq` and branches on these exit
 * codes, and it is on the other side of a boundary no test can cross — it runs on the
 * host, outside every container (D-032). So the contract cannot be checked end to end
 * by anything; it can only be pinned from this side, which is what these tests do:
 *
 *   - claim prints exactly one JSON object, or exactly nothing, and exits 0 either way.
 *     Nothing is the ordinary case — the timer ticks every minute and updates are rare —
 *     and an exit code other than 0 for it would light `systemctl status` red all year;
 *   - the object has the four keys the script reads, and no diagnostic text shares the
 *     stream with them;
 *   - finish takes the log from standard input, because it is kilobytes of rebuild
 *     output with newlines and quotes in it.
 */
final class UpdaterCommandsTest extends KernelTestCase
{
    private Application $console;
    private Connection $connection;
    private User $administrator;

    protected function setUp(): void
    {
        $this->console = new Application(self::bootKernel());
        $this->console->setAutoExit(false);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.dependency_updates, ws.updater_heartbeat, '
            . 'ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->administrator = new User('admin@web-systems.pl', 'Artur Ograbek');
        $em->persist($this->administrator);
        $em->flush();
    }

    public function testHeartbeatSaysNothingAndSucceeds(): void
    {
        $tester = $this->invoke('ws:updater:heartbeat');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay(), 'a command that runs every minute forever must not chat');
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM ws.updater_heartbeat'),
        );
    }

    public function testClaimPrintsNothingAndSucceedsWhenThereIsNoWork(): void
    {
        $tester = $this->invoke('ws:updater:claim');

        self::assertSame(0, $tester->getStatusCode(), 'no work is the normal case, not a failure');
        self::assertSame('', trim($tester->getDisplay()));
    }

    public function testClaimPrintsOneJsonObjectWithTheFourFieldsTheAgentReads(): void
    {
        $id = $this->seedPendingOrder();

        $tester = $this->invoke('ws:updater:claim');

        self::assertSame(0, $tester->getStatusCode());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($tester->getDisplay()), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['id', 'name', 'fromVersion', 'toVersion'], array_keys($decoded));
        self::assertSame($id, $decoded['id']);
        self::assertSame('mempalace', $decoded['name']);
        self::assertSame('3.7.0', $decoded['fromVersion']);
        self::assertSame('3.9.0', $decoded['toVersion']);
    }

    public function testFinishTakesTheLogFromStandardInput(): void
    {
        $id = $this->seedPendingOrder();
        $this->invoke('ws:updater:claim');

        $tester = $this->invoke(
            'ws:updater:finish',
            ['id' => $id, '--status' => 'failed'],
            ['test semantyki NIE PRZESZEDŁ'],
        );

        self::assertSame(0, $tester->getStatusCode());

        $row = $this->connection->fetchAssociative(
            'SELECT status, log FROM ws.dependency_updates WHERE id = :id',
            ['id' => $id],
        );
        self::assertNotFalse($row);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('semantyki', (string) $row['log']);
    }

    public function testFinishRefusesAnInvocationItCannotInterpret(): void
    {
        $id = $this->seedPendingOrder();

        // Exit 2, not 1: the agent distinguishes "I was called wrongly" from "the order
        // is gone", and only the second one means something happened on the host that
        // the panel will never show.
        self::assertSame(2, $this->invoke('ws:updater:finish', ['id' => $id])->getStatusCode());
        self::assertSame(
            2,
            $this->invoke('ws:updater:finish', ['id' => $id, '--status' => 'maybe'])->getStatusCode(),
        );
        self::assertSame(
            2,
            $this->invoke('ws:updater:finish', ['id' => 'zle; id', '--status' => 'failed'])->getStatusCode(),
        );

        self::assertSame(
            'pending',
            $this->connection->fetchOne('SELECT status FROM ws.dependency_updates WHERE id = :id', ['id' => $id]),
            'a malformed report must not touch the order it named',
        );
    }

    public function testFinishFailsWhenNoOrderIsWaitingForAResult(): void
    {
        // The agent keeps the log on the host in this case, because the panel will not
        // show it — so the exit code has to be the one that tells it to.
        $tester = $this->invoke(
            'ws:updater:finish',
            ['id' => '0191b8d2-0000-7000-8000-000000000000', '--status' => 'succeeded'],
            ['cokolwiek'],
        );

        self::assertSame(1, $tester->getStatusCode());
    }

    /**
     * @param array<string, string> $arguments
     * @param list<string>          $stdin
     */
    private function invoke(string $command, array $arguments = [], array $stdin = []): CommandTester
    {
        $tester = new CommandTester($this->console->find($command));

        if ([] !== $stdin) {
            $tester->setInputs($stdin);
        }

        $tester->execute($arguments);

        return $tester;
    }

    private function seedPendingOrder(): string
    {
        $id = (string) $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO ws.dependency_updates
                    (id, name, from_version, to_version, status, requested_by, requested_at)
                VALUES (gen_random_uuid(), 'mempalace', '3.7.0', '3.9.0', 'pending', :who, now())
                RETURNING id
                SQL,
            ['who' => $this->administrator->getId()->toRfc4122()],
        );

        return $id;
    }
}
