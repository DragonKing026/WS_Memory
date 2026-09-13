<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The audit screen.
 *
 * The fixture is 123 entries written straight into the table with chosen timestamps. Going
 * through the application to produce them would mean performing 123 audited actions to test
 * a listing, and the timestamps — which every paging and range assertion here depends on —
 * would be whatever the clock said.
 *
 * Three of these tests are the ones worth keeping if the rest ever have to go:
 *
 *   - **actor resolution for all three kinds.** A person, an agent acting under that same
 *     person's token, and an entry with no actor at all. An agent's row carries BOTH
 *     identifiers, so an implementation that checks the user id first reports every agent
 *     action as a human one — and an audit log that mislabels who acted is worse than none,
 *     because it is believed;
 *   - **the date range's bounds.** Inclusive at the bottom, exclusive at the top, proven
 *     with an entry sitting exactly on the upper bound. Any other pairing makes a reader
 *     paging through a month a day at a time either see an entry twice or never see it;
 *   - **no way to delete anything.** A log that can be cleared from the panel is not a log
 *     (D-016), so the absence of that route is asserted rather than assumed.
 *
 * Note the truncation of `ws.audit_log` at the end of setUp. Signing in and issuing a token
 * are audited actions, so the fixture has to be laid down after them or the counts would
 * include the preparations.
 *
 * One more thing the fixture cannot avoid, and it is a property of the system rather than of
 * this test: the firewall is stateless, so the JWT authenticator succeeds afresh on every
 * request and LoginAuditSubscriber records a `user.login` for each one. Reading the audit log
 * therefore adds an entry to it — the reader's own arrival, and it is the newest thing in the
 * answer. Tests that care about ordering or about a total ask for `space=wiedza`, which the
 * sign-in entries do not carry; the two that count the whole log expect it explicitly.
 */
final class AdminAuditTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    /** Entries in the fixture: 120 ordinary ones plus an agent's, a system one and an old one. */
    private const SEEDED = 123;

    /** Of those, the ones in `wiedza`: the 120 plus the agent's. */
    private const SEEDED_IN_WIEDZA = 121;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $writer;
    private User $globalAdmin;
    private string $agentTokenId;
    private string $adminToken;
    private string $writerToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, '
            . 'ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        $this->writer = ($accept)(($issue)('piszacy@web-systems.pl')->plainToken, 'Piszący', self::PASSWORD);
        $this->globalAdmin = ($accept)(
            ($issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Administrator',
            self::PASSWORD,
        );

        $this->agentTokenId = $container->get(IssueAgentToken::class)($this->writer, 'agent CI')->tokenId;

        $this->adminToken = $this->tokenFor('admin@web-systems.pl');
        $this->writerToken = $this->tokenFor('piszacy@web-systems.pl');

        $this->em->getConnection()->executeStatement('TRUNCATE ws.audit_log');
        $this->seedJournal();
    }

    public function testWriterCannotReadTheJournal(): void
    {
        $this->get('/api/admin/audit', $this->writerToken);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('administratora', $this->json()['error']);
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->client->request('GET', '/api/admin/audit');

        self::assertResponseStatusCodeSame(401);
    }

    public function testDefaultPageIsAHundredEntriesNewestFirst(): void
    {
        $this->get('/api/admin/audit', $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = $this->json();
        self::assertSame(
            ['entries', 'count', 'limit', 'offset', 'hasMore', 'actions'],
            array_keys($payload),
        );
        self::assertCount(100, $payload['entries']);
        // The fixture plus this very request's own sign-in entry. Reading the log adds to
        // it, which is not a flaw to be worked around: the alternative is a surface that
        // reads the record of who read the record without being recorded.
        self::assertSame(self::SEEDED + 1, $payload['count'], 'count is the whole log, not this page');
        self::assertSame(100, $payload['limit']);
        self::assertSame(0, $payload['offset']);
        self::assertTrue($payload['hasMore']);

        $first = $payload['entries'][0];
        self::assertSame(
            ['id', 'action', 'actor', 'actorKind', 'space', 'target', 'ip', 'createdAt'],
            array_keys($first),
            'the frontend reads these keys by name; a nullable one must be present, not absent',
        );
        self::assertSame('user.login', $first['action'], 'the newest entry is this reader arriving');
        self::assertGreaterThan($payload['entries'][1]['createdAt'], $first['createdAt']);
        self::assertGreaterThan($payload['entries'][2]['createdAt'], $payload['entries'][1]['createdAt']);
    }

    public function testActorIsResolvedForPersonAgentAndSystemAlike(): void
    {
        $this->get('/api/admin/audit?space=wiedza', $this->adminToken);
        $entries = $this->json()['entries'];

        // The agent's row carries its token AND the owner's id, because an agent always
        // acts under somebody's authority. The token is what names it.
        self::assertSame('mcp.tool_called', $entries[0]['action']);
        self::assertSame('agent CI (Piszący)', $entries[0]['actor']);
        self::assertSame('agent', $entries[0]['actorKind']);

        self::assertSame('memory.remember', $entries[1]['action']);
        self::assertSame('Piszący', $entries[1]['actor']);
        self::assertSame('czlowiek', $entries[1]['actorKind']);
        self::assertSame('wiedza', $entries[1]['space']);
        self::assertSame(['drawer' => 'szuflada'], $entries[1]['target']);
        self::assertSame('10.0.0.1', $entries[1]['ip']);

        // Written by the application on nobody's behalf — a scheduled check, a console
        // command. Neither identifier is set, and that absence is informative, not a loss.
        $this->get('/api/admin/audit?action=dependency.checked', $this->adminToken);
        $system = $this->json()['entries'][0];
        self::assertSame('system', $system['actor']);
        self::assertSame('system', $system['actorKind']);
        self::assertNull($system['space'], 'an action belonging to no space says so');
        self::assertNull($system['ip'], 'no request, no address');
    }

    public function testPagingReachesTheEndOfTheLog(): void
    {
        $this->get('/api/admin/audit?space=wiedza&offset=100', $this->adminToken);

        $payload = $this->json();
        self::assertCount(self::SEEDED_IN_WIEDZA - 100, $payload['entries']);
        self::assertSame(self::SEEDED_IN_WIEDZA, $payload['count']);
        self::assertSame(100, $payload['offset']);
        self::assertFalse($payload['hasMore']);
    }

    public function testLimitIsCappedRatherThanObeyed(): void
    {
        $this->get('/api/admin/audit?space=wiedza&limit=100000', $this->adminToken);

        // The one table that grows without bound, and the one screen that reads all of it:
        // the ceiling is what keeps a single request from carrying the whole log.
        self::assertSame(500, $this->json()['limit']);
        self::assertCount(self::SEEDED_IN_WIEDZA, $this->json()['entries']);
    }

    public function testFilterByAction(): void
    {
        $this->get('/api/admin/audit?action=memory.remember&limit=500', $this->adminToken);

        $payload = $this->json();
        self::assertSame(120, $payload['count']);
        self::assertCount(120, $payload['entries']);

        foreach ($payload['entries'] as $entry) {
            self::assertSame('memory.remember', $entry['action']);
        }
    }

    public function testFilterByActionThatNeverOccurred(): void
    {
        $this->get('/api/admin/audit?action=space.deleted', $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);
        self::assertSame([], $this->json()['entries']);
    }

    public function testFilterBySpace(): void
    {
        $this->get('/api/admin/audit?space=alfa', $this->adminToken);

        $payload = $this->json();
        self::assertSame(1, $payload['count']);
        self::assertSame('space.member_added', $payload['entries'][0]['action']);
        self::assertSame('Administrator', $payload['entries'][0]['actor']);
    }

    public function testDateRangeIsInclusiveBelowAndExclusiveAbove(): void
    {
        $this->get(
            '/api/admin/audit?since=2026-09-10T11:58:00&before=2026-09-10T12:00:00',
            $this->adminToken,
        );

        $payload = $this->json();
        // 11:58 and 11:59 are in; the entry sitting exactly on 12:00 is out. Consecutive
        // ranges then neither overlap nor drop an entry between them.
        self::assertSame(2, $payload['count']);
        self::assertSame('2026-09-10T11:59:00+00:00', $payload['entries'][0]['createdAt']);
        self::assertSame('2026-09-10T11:58:00+00:00', $payload['entries'][1]['createdAt']);
    }

    public function testFilterByPersonAlsoFindsWhatTheirAgentDid(): void
    {
        $this->get(
            '/api/admin/audit?actor=' . $this->writer->getId()->toRfc4122() . '&limit=500',
            $this->adminToken,
        );

        // 120 of their own plus the one their token wrote. This is the useful reading of
        // "show me what this person did", and it works because an agent's entry carries the
        // owner's id alongside the token's.
        self::assertSame(121, $this->json()['count']);
    }

    public function testFilterByAgentTokenFindsOnlyTheAgent(): void
    {
        $this->get('/api/admin/audit?actor=' . $this->agentTokenId, $this->adminToken);

        $payload = $this->json();
        self::assertSame(1, $payload['count']);
        self::assertSame('agent', $payload['entries'][0]['actorKind']);
    }

    public function testActionListDescribesTheLogAndNotThePage(): void
    {
        // `user.login` is in the list because this reader's own arrival is in the log.
        $expected = [
            'dependency.checked', 'mcp.tool_called', 'memory.remember', 'space.member_added', 'user.login',
        ];

        $this->get('/api/admin/audit', $this->adminToken);
        self::assertSame($expected, $this->json()['actions']);

        // Still the whole vocabulary under a filter: options that vanished the moment one
        // was used would leave a reader unable to get back to where they were.
        $this->get('/api/admin/audit?action=space.member_added', $this->adminToken);
        self::assertSame($expected, $this->json()['actions']);
    }

    public function testMalformedDateIsRefusedRatherThanIgnored(): void
    {
        $this->get('/api/admin/audit?since=wczoraj', $this->adminToken);

        // Dropped silently, the filter would widen: the screen would say "entries since
        // yesterday" above a year of them, and nothing would tell the reader.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Zakres dat', $this->json()['error']);
    }

    public function testMalformedActorIsRefused(): void
    {
        $this->get('/api/admin/audit?actor=piszacy@web-systems.pl', $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('actor', $this->json()['error']);
    }

    public function testBlankFiltersAreNotFilters(): void
    {
        $this->get('/api/admin/audit?action=&space=&actor=&since=&before=', $this->adminToken);

        // A panel clearing its fields sends empty strings. Taken literally they would match
        // nothing, and an empty audit log is exactly the wrong thing to show by accident.
        self::assertResponseIsSuccessful();
        self::assertSame(self::SEEDED + 1, $this->json()['count']);
    }

    public function testThereIsNoWayToDeleteAnEntry(): void
    {
        $this->client->request(
            'DELETE',
            '/api/admin/audit',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken],
        );

        // Not an oversight to be fixed later: an audit trail an administrator can erase has
        // recorded nothing (D-016). The route does not exist, and this test is what says so
        // out loud.
        self::assertResponseStatusCodeSame(405);
        // Exactly the fixture, with nothing added either: routing refuses the method before
        // the firewall authenticates anybody, so this request did not even record an arrival.
        self::assertSame(
            self::SEEDED,
            (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM ws.audit_log'),
        );
    }

    /**
     * 123 entries with chosen timestamps: 120 ordinary writes by a person, one by their
     * agent, one by the system, and one in another space long before the rest.
     */
    private function seedJournal(): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.audit_log (id, actor_user_id, action, space_slug, target, ip, created_at)
                SELECT
                    gen_random_uuid(),
                    :user,
                    'memory.remember',
                    'wiedza',
                    '{"drawer":"szuflada"}'::jsonb,
                    '10.0.0.1',
                    TIMESTAMP '2026-09-10 12:00:00' - (i || ' minutes')::interval
                FROM generate_series(1, 120) AS s(i)
                SQL,
            ['user' => $this->writer->getId()->toRfc4122()],
        );

        $connection->insert('ws.audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'actor_user_id' => $this->writer->getId()->toRfc4122(),
            'actor_agent_token_id' => $this->agentTokenId,
            'action' => 'mcp.tool_called',
            'space_slug' => 'wiedza',
            'target' => '{"tool":"ws_search"}',
            'created_at' => '2026-09-10 12:05:00',
        ]);

        // Neither identifier: nobody's behalf, and the screen has to say so.
        $connection->insert('ws.audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'action' => 'dependency.checked',
            'target' => '{"name":"mempalace"}',
            'created_at' => '2026-09-10 12:00:00',
        ]);

        $connection->insert('ws.audit_log', [
            'id' => Uuid::v7()->toRfc4122(),
            'actor_user_id' => $this->globalAdmin->getId()->toRfc4122(),
            'action' => 'space.member_added',
            'space_slug' => 'alfa',
            'target' => '{"member":"piszacy@web-systems.pl","role":"writer"}',
            'created_at' => '2026-09-01 08:00:00',
        ]);
    }

    private function tokenFor(string $email): string
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );

        return $this->json()['token'];
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
    }
}
