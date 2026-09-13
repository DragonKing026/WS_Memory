<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ordering an update through the panel's API.
 *
 * Every test here is a refusal except two, and that balance is the point. The backend
 * does not update anything — it writes a row that a script on the host will act on
 * (D-032) — so the value of this endpoint is almost entirely in what it declines to
 * write:
 *
 *   - a version that is not three numbers never reaches `pip install` on the host;
 *   - a version nothing vouches for never gets a rebuild started for it;
 *   - a second order never queues a second rebuild behind the first;
 *   - an order is never written when no agent is reporting, because a pending order
 *     nobody takes blocks every later one through the partial unique index.
 *
 * `PYPI_URL` points at a port nothing listens on for the whole suite (see
 * phpunit.dist.xml), which makes the catalogue unreachable in every test here. That is
 * not an obstacle to work around — it is the production case worth proving: an order
 * for the version the last successful check recorded must still go through with PyPI
 * down, and an order for any other version must not.
 */
final class AdminDependencyUpdateTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    /** The version the last successful check recorded, so the one an order may name. */
    private const KNOWN_LATEST = '3.9.0';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.dependency_state, ws.dependency_updates, ws.updater_heartbeat, '
            . 'ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        ($accept)(($issue)('piszacy@web-systems.pl')->plainToken, 'Piszący', self::PASSWORD);
        ($accept)(
            ($issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Artur Ograbek',
            self::PASSWORD,
        );

        // The state a successful check would have left: 3.7.0 running, 3.9.0 available.
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_state (name, installed_version, latest_version, checked_at)
                VALUES ('mempalace', '3.7.0', :latest, '2026-09-13 11:00:00+00')
                SQL,
            ['latest' => self::KNOWN_LATEST],
        );
    }

    public function testOrdinaryUserCannotOrderAnUpdate(): void
    {
        $this->reportHeartbeat();

        $this->order(self::KNOWN_LATEST, $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            0,
            $this->countOrders(),
            'a refused order must not leave a row that blocks the next real one',
        );
    }

    public function testUnknownDependencyIsNotFound(): void
    {
        $this->reportHeartbeat();

        $this->client->request(
            'POST',
            '/api/admin/dependencies/redis/update',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor('admin@web-systems.pl'),
            ],
            content: (string) json_encode(['toVersion' => '7.0.0']),
        );

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTargets(): iterable
    {
        yield 'two components' => ['3.9'];
        yield 'not a version at all' => ['najnowsza'];
        yield 'a shell attempt' => ['3.9.0; rm -rf /'];
        yield 'a pre-release' => ['3.9.0rc1'];
        yield 'empty' => [''];
    }

    /**
     * Nothing but X.Y.Z gets written, whatever it looks like.
     *
     * The list includes a version that is perfectly valid to a package index — a release
     * candidate — because the question is not "is this a version" but "may this be
     * handed to the host", and the answer for anything unstable is no whatever its
     * ordering against a stable release would be.
     */
    #[DataProvider('malformedTargets')]
    public function testTargetThatIsNotThreeNumbersIsRefused(string $target): void
    {
        $this->reportHeartbeat();

        $this->order($target, $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('X.Y.Z', $this->json()['error']);
        self::assertSame(0, $this->countOrders(), 'nothing reaches the host from a refused target');
    }

    public function testTargetOutsideTheReleaseCatalogueIsRefused(): void
    {
        $this->reportHeartbeat();

        // Well formed, newer than everything, and not a release anybody ever published.
        // Accepting it would start a rebuild that fails at `pip install` — after the
        // palace had already been taken down for it.
        $this->order('4.0.0', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countOrders());
    }

    public function testOrderIsRefusedWhenNoAgentIsReporting(): void
    {
        // No heartbeat at all: nothing on the host has ever said it could do this.
        $this->order(self::KNOWN_LATEST, $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(503);
        self::assertStringContainsString('Agent aktualizacji', $this->json()['error']);
        self::assertSame(
            0,
            $this->countOrders(),
            'an order nobody would take must not be written: pending rows block every later order',
        );
    }

    public function testStaleHeartbeatCountsAsNoAgent(): void
    {
        // Installed months ago, timer failing since. This is the case the two booleans
        // in `updater` exist to tell apart, and the one where a button would do nothing.
        $this->reportHeartbeat('-20 minutes');

        $this->order(self::KNOWN_LATEST, $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(503);

        $this->get('/api/admin/dependencies', $this->tokenFor('admin@web-systems.pl'));
        $updater = $this->json()['updater'];
        self::assertTrue($updater['installed'], 'a pulse was heard once, so an agent was installed');
        self::assertFalse($updater['healthy'], 'and it is not reporting now, which is what blocks the button');
    }

    public function testOrderingAnUpdateRecordsItAndAnswersWithTheWholePanelState(): void
    {
        $this->reportHeartbeat();

        $this->order(self::KNOWN_LATEST, $this->tokenFor('admin@web-systems.pl'));

        // 202: the order is written and nothing has happened yet. 201 would claim a
        // resource was created for the caller to fetch; 200 would suggest it is done.
        self::assertResponseStatusCodeSame(202);

        $payload = $this->json();
        self::assertSame(['dependency', 'updater'], array_keys($payload));

        $pending = $payload['dependency']['pendingUpdate'];
        self::assertNotNull($pending);
        self::assertSame(
            ['id', 'status', 'fromVersion', 'toVersion', 'requestedBy', 'requestedAt', 'finishedAt', 'log'],
            array_keys($pending),
            'the frontend reads these keys by name; a nullable one must be present, not absent',
        );
        self::assertSame('pending', $pending['status'], 'nothing has claimed it yet, and that is a state of its own');
        self::assertSame('3.7.0', $pending['fromVersion']);
        self::assertSame(self::KNOWN_LATEST, $pending['toVersion']);
        self::assertSame('Artur Ograbek', $pending['requestedBy'], 'an update is an act, and acts have authors');
        self::assertNull($pending['finishedAt']);
        self::assertNull($pending['log']);
        self::assertSame([], $payload['dependency']['history'], 'the only order there is, is the headline one');
        self::assertTrue($payload['updater']['healthy']);

        // An infrastructure change leaves a trace carrying who ordered it (D-016).
        $audited = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id, target FROM ws.audit_log WHERE action = 'dependency.update.requested'"
        );
        self::assertNotFalse($audited, 'updating a dependency without a trace is not an option');
        self::assertNotNull($audited['actor_user_id']);
        self::assertStringContainsString(self::KNOWN_LATEST, (string) $audited['target']);
    }

    public function testSecondOrderForTheSameDependencyIsRefused(): void
    {
        $this->reportHeartbeat();
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->order(self::KNOWN_LATEST, $token);
        self::assertResponseStatusCodeSame(202);

        $this->order(self::KNOWN_LATEST, $token);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('już zlecona', $this->json()['error']);
        self::assertSame(
            1,
            $this->countOrders(),
            'the second order must not be queued behind the first: that is two rebuilds of one image',
        );
    }

    public function testFinishedOrderStaysOnScreenAndEarlierOnesBecomeHistory(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        // Seven closed orders, oldest first. The panel headlines the newest and keeps
        // five behind it — the seventh is deliberately over the limit.
        for ($i = 1; $i <= 7; ++$i) {
            $this->seedFinishedOrder(
                requestedAt: \sprintf('2026-09-1%d 10:00:00+00', $i),
                status: 7 === $i ? 'failed' : 'succeeded',
                log: 7 === $i ? 'test semantyki NIE PRZESZEDŁ' : 'gotowe',
            );
        }

        $this->get('/api/admin/dependencies', $token);

        $dependency = $this->json()['dependencies'][0];

        // A finished order is still reported as `pendingUpdate`: its log is the only
        // trace of a failed semantic test, and a failed semantic test is silent (D-003).
        // Dropping it the moment it ended would hide the one thing worth reading.
        self::assertSame('failed', $dependency['pendingUpdate']['status']);
        self::assertStringContainsString('semantyki', (string) $dependency['pendingUpdate']['log']);
        self::assertNotNull($dependency['pendingUpdate']['finishedAt']);

        self::assertCount(5, $dependency['history'], 'five earlier orders, not every order ever');
        self::assertSame(
            ['2026-09-16T10:00:00+00:00', '2026-09-15T10:00:00+00:00', '2026-09-14T10:00:00+00:00'],
            array_map(
                static fn (array $entry): string => (string) $entry['requestedAt'],
                \array_slice($dependency['history'], 0, 3),
            ),
            'newest first, and never the one already shown as the headline',
        );
    }

    private function reportHeartbeat(string $modifier = 'now'): void
    {
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO ws.updater_heartbeat (id, seen_at) VALUES (1, :seenAt)
                ON CONFLICT (id) DO UPDATE SET seen_at = EXCLUDED.seen_at
                SQL,
            ['seenAt' => (new \DateTimeImmutable($modifier))->format(\DateTimeInterface::ATOM)],
        );
    }

    /**
     * A closed order, written straight to the table.
     *
     * Written by hand rather than through the service and the agent's commands, because
     * what is being tested here is the shape of the panel's answer, and going the long
     * way round would make six of these depend on the agent's transitions working.
     */
    private function seedFinishedOrder(string $requestedAt, string $status, string $log): void
    {
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_updates
                    (id, name, from_version, to_version, status, requested_by, requested_at, started_at, finished_at, log)
                VALUES (
                    gen_random_uuid(), 'mempalace', '3.7.0', '3.9.0', :status,
                    (SELECT id FROM ws.users WHERE email = 'admin@web-systems.pl'),
                    :requestedAt, :requestedAt, :requestedAt, :log
                )
                SQL,
            ['status' => $status, 'requestedAt' => $requestedAt, 'log' => $log],
        );
    }

    private function countOrders(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM ws.dependency_updates');
    }

    private function order(string $toVersion, string $token): void
    {
        $this->client->request(
            'POST',
            '/api/admin/dependencies/mempalace/update',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: (string) json_encode(['toVersion' => $toVersion]),
        );
    }

    private function tokenFor(string $email): string
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => self::PASSWORD]),
        );

        /** @var string $token */
        $token = $this->json()['token'];

        return $token;
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }
}
