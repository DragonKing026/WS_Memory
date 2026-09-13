<?php

declare(strict_types=1);

namespace App\Tests\Application\Dependency;

use App\Application\Dependency\UpdaterAgent;
use App\Application\Dependency\UpdateRequestService;
use App\Domain\Dependency\DependencyRecord;
use App\Domain\Dependency\DependencyStatus;
use App\Domain\Dependency\UpdateRefused;
use App\Domain\Dependency\UpdateRequest;
use App\Domain\Dependency\UpdateRequestRepository;
use App\Domain\Dependency\UpdateStatus;
use App\Domain\Dependency\Version;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The concurrency half of TODO-015, which HTTP cannot reach.
 *
 * Every test here is about two things happening at once, or about something not
 * happening at all — the cases an API test cannot pose because it does one request at a
 * time:
 *
 *   - two agent runs overlapping (the host's timer ticks every minute and a rebuild
 *     takes longer, so this is the normal case, not the exotic one);
 *   - two orders written past the service's own check, which is what the partial unique
 *     index in the database is for;
 *   - an agent killed mid-run, whose claimed order would otherwise block the dependency
 *     for good.
 *
 * These go through the real repository against the real database on purpose. All three
 * properties are properties of the SQL — `FOR UPDATE SKIP LOCKED`, a partial unique
 * index, one `UPDATE` with a cutoff — and a doubled repository would prove only that
 * the double behaves.
 */
final class UpdaterAgentTest extends KernelTestCase
{
    private UpdateRequestRepository $requests;
    private UpdaterAgent $agent;
    private UpdateRequestService $orders;
    private Connection $connection;
    private User $administrator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->requests = $container->get(UpdateRequestRepository::class);
        $this->agent = $container->get(UpdaterAgent::class);
        $this->orders = $container->get(UpdateRequestService::class);

        $em = $container->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.dependency_state, ws.dependency_updates, ws.updater_heartbeat, '
            . 'ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->administrator = new User('admin@web-systems.pl', 'Artur Ograbek');
        $this->administrator->promoteToGlobalAdmin();
        $em->persist($this->administrator);
        $em->flush();

        // The state a successful check would have left, so an order may name 3.9.0
        // without reaching PyPI — which is unreachable for the whole suite by design.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_state (name, installed_version, latest_version, checked_at)
                VALUES ('mempalace', '3.7.0', '3.9.0', '2026-09-13 11:00:00+00')
                SQL
        );
    }

    public function testHeartbeatIsWhatMakesTheUpdaterCountAsPresent(): void
    {
        self::assertFalse(
            $this->orders->updaterState()->installed(),
            'no pulse has ever been heard, which is not the same as a late one',
        );

        $this->agent->heartbeat();

        $state = $this->orders->updaterState();
        self::assertTrue($state->installed());
        self::assertTrue($state->healthy);
    }

    public function testClaimingMovesAnOrderToRunningAndLeavesNothingForTheSecondRun(): void
    {
        $this->agent->heartbeat();
        $ordered = $this->orders->request($this->administrator, 'mempalace', Version::parse('3.9.0'));

        $claimed = $this->agent->claimNext();

        self::assertNotNull($claimed);
        self::assertSame($ordered->id, $claimed->id);
        self::assertSame(UpdateStatus::Running, $claimed->status);
        self::assertNotNull($claimed->startedAt, 'started_at is what the abandonment timeout measures from');

        // The second run is the point. Two agent runs overlap whenever one takes longer
        // than the timer's minute, and both taking the same order would mean two
        // concurrent rebuilds of the palace image from two different `.env` values.
        self::assertNull($this->agent->claimNext(), 'a claimed order must not be claimable again');

        self::assertSame(
            'running',
            $this->connection->fetchOne(
                'SELECT status FROM ws.dependency_updates WHERE id = :id',
                ['id' => $ordered->id],
            ),
        );
    }

    public function testFinishingRecordsTheOutcomeAndTheAgentsLog(): void
    {
        $this->agent->heartbeat();
        $ordered = $this->orders->request($this->administrator, 'mempalace', Version::parse('3.9.0'));
        $this->agent->claimNext();

        $log = "[12:00:00] Kopia zapasowa gotowa\n[12:04:11] test semantyki NIE PRZESZEDŁ";

        self::assertTrue($this->agent->finish($ordered->id, false, $log));

        $stored = $this->requests->latestFor('mempalace');
        self::assertNotNull($stored);
        self::assertSame(UpdateStatus::Failed, $stored->status);
        self::assertNotNull($stored->finishedAt);
        // Verbatim, newlines and all: this text is the only trace a failed semantic test
        // ever leaves, because a drop in search relevance is otherwise silent (D-003).
        self::assertSame($log, $stored->log);

        self::assertFalse(
            $this->agent->finish($ordered->id, true, 'jednak się udało'),
            'an order already closed must not have its result overwritten by a later report',
        );
        self::assertSame(UpdateStatus::Failed, $this->requests->latestFor('mempalace')?->status);
    }

    public function testAnOrderIdOutsideTheAgentsAlphabetIsRefused(): void
    {
        // The id travels to the host's command line (`ws:updater:finish <id>`), where the
        // agent checks it against this same pattern. Refused on both sides deliberately.
        $this->expectException(\InvalidArgumentException::class);

        $this->agent->finish('nie taki; id', true, '');
    }

    public function testOrderClaimedAndNeverReportedIsClosedOnTheNextRead(): void
    {
        $abandoned = $this->seedOrder(
            status: 'running',
            requestedAt: '-90 minutes',
            startedAt: '-46 minutes',
        );

        // Nothing sweeps in the background: the answer is worked out when somebody looks,
        // which is also the only moment it matters.
        $view = $this->orders->viewOf(new DependencyStatus('MemPalace', new DependencyRecord('mempalace')));

        $closed = $view->latest;
        self::assertNotNull($closed);
        self::assertSame($abandoned, $closed->id);
        self::assertSame(UpdateStatus::Failed, $closed->status);
        self::assertStringContainsString(
            '45 minut',
            (string) $closed->log,
            'a failed order with an empty log tells an administrator nothing at all',
        );

        // And the dependency is free again — without this, one killed agent would refuse
        // every future order for good, because the partial unique index counts a running
        // row as work in flight.
        $this->agent->heartbeat();
        $this->orders->request($this->administrator, 'mempalace', Version::parse('3.9.0'));

        self::assertSame(2, $this->countOrders());
    }

    public function testOrderClaimedRecentlyIsLeftAloneAndStillBlocks(): void
    {
        $this->seedOrder(status: 'running', requestedAt: '-10 minutes', startedAt: '-9 minutes');
        $this->agent->heartbeat();

        $this->expectException(UpdateRefused::class);
        // A rebuild plus a health wait plus the semantic test takes minutes. Sweeping
        // that away as abandoned would let a second order start while the first was
        // halfway through replacing the container.
        $this->orders->request($this->administrator, 'mempalace', Version::parse('3.9.0'));
    }

    public function testDatabaseRefusesASecondActiveOrderEvenWithTheServiceBypassed(): void
    {
        $this->seedOrder(status: 'pending', requestedAt: '-1 minute');

        // Straight SQL, deliberately going around every check written in PHP. Two
        // administrators clicking in the same millisecond both pass the service's own
        // look at the table, so the constraint that actually holds has to be in the
        // database — and this is the test that says so.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_updates
                    (id, name, to_version, status, requested_by, requested_at)
                VALUES (gen_random_uuid(), 'mempalace', '3.9.0', 'pending', :who, now())
                SQL,
            ['who' => $this->administrator->getId()->toRfc4122()],
        );
    }

    public function testTerminalOrdersAreOutsideThatConstraint(): void
    {
        // The index is about work in flight, not about how many times MemPalace was ever
        // updated. Six closed orders for one dependency must be ordinary, or history
        // would be impossible to keep.
        for ($i = 0; $i < 3; ++$i) {
            $this->seedOrder(status: 'succeeded', requestedAt: \sprintf('-%d days', $i + 2));
            $this->seedOrder(status: 'failed', requestedAt: \sprintf('-%d days', $i + 5));
        }

        self::assertSame(6, $this->countOrders());
    }

    public function testRepositoryTranslatesTheRaceIntoTheSameRefusalAsTheCheck(): void
    {
        $this->agent->heartbeat();
        $this->orders->request($this->administrator, 'mempalace', Version::parse('3.9.0'));

        // What the loser of the race hits: the service's check has already passed, and
        // the insert is what fails. The caller must not be able to tell the two apart —
        // one is the check working, the other is the check being a moment too late.
        $this->expectException(UpdateRefused::class);

        $this->requests->add(new UpdateRequest(
            Uuid::v7()->toRfc4122(),
            'mempalace',
            Version::parse('3.7.0'),
            Version::parse('3.9.0'),
            UpdateStatus::Pending,
            $this->administrator->getId()->toRfc4122(),
            new \DateTimeImmutable(),
        ));
    }

    /**
     * An order written straight to the table, in whatever state the test needs.
     *
     * @return string the order's id
     */
    private function seedOrder(string $status, string $requestedAt, ?string $startedAt = null): string
    {
        $id = Uuid::v7()->toRfc4122();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_updates
                    (id, name, from_version, to_version, status, requested_by, requested_at, started_at, finished_at)
                VALUES (:id, 'mempalace', '3.7.0', '3.9.0', :status, :who, :requestedAt, :startedAt, :finishedAt)
                SQL,
            [
                'id' => $id,
                'status' => $status,
                'who' => $this->administrator->getId()->toRfc4122(),
                'requestedAt' => (new \DateTimeImmutable($requestedAt))->format(\DateTimeInterface::ATOM),
                'startedAt' => null === $startedAt
                    ? null
                    : (new \DateTimeImmutable($startedAt))->format(\DateTimeInterface::ATOM),
                // Terminal states get a finish time so the row is not self-contradictory;
                // a `succeeded` order with no `finished_at` is not a state the system
                // can produce, and seeding one would be testing against fiction.
                'finishedAt' => \in_array($status, ['succeeded', 'failed'], true)
                    ? (new \DateTimeImmutable($requestedAt))->format(\DateTimeInterface::ATOM)
                    : null,
            ],
        );

        return $id;
    }

    private function countOrders(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM ws.dependency_updates');
    }
}
