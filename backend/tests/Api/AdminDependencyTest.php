<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The dependency panel's API.
 *
 * The substantive test here is the last one. `PYPI_URL` points at a port nothing
 * listens on for the whole suite (see phpunit.dist.xml), so a check performed in a
 * test always fails — which is exactly the case that has to be right. An
 * unreachable catalogue must leave the previously known release in place and say
 * why it could not be confirmed, because "nie udało się sprawdzić" and "brak
 * nowszej wersji" look identical in an empty field and mean opposite things.
 *
 * The rest guards the access rule and the shape the frontend reads.
 */
final class AdminDependencyTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

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
            'Administrator',
            self::PASSWORD,
        );
    }

    public function testOrdinaryUserCannotSeeDependencies(): void
    {
        $this->get('/api/admin/dependencies', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('administratora', $this->json()['error']);
    }

    public function testOrdinaryUserCannotForceACheck(): void
    {
        // The read half and the write half of an administration surface must not
        // drift apart: this is where update orders will later be placed.
        $this->postJson('/api/admin/dependencies/mempalace/check', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->client->request('GET', '/api/admin/dependencies');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminSeesTheDocumentedShape(): void
    {
        // Deliberately 3.9.0 against 3.10.0: an implementation comparing strings
        // reports no update available here, and reports it without failing.
        $this->seed(installed: '3.9.0', latest: '3.10.0', checkedAt: '2026-09-13 11:00:00+00');

        $this->get('/api/admin/dependencies', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = $this->json();
        self::assertArrayHasKey('dependencies', $payload);
        self::assertCount(1, $payload['dependencies']);

        $dependency = $payload['dependencies'][0];
        self::assertSame(
            [
                'name', 'label', 'installed', 'pinned', 'latest', 'updateAvailable', 'checkedAt', 'checkProblem',
                'pendingUpdate', 'history',
            ],
            array_keys($dependency),
            'the frontend reads these keys by name; a nullable one must be present, not absent',
        );

        // The agent's state travels with every answer, never as a separate request: an
        // available update plus no agent is a sentence about the agent rather than a
        // button, and a screen that learned one fact without the other would offer one.
        self::assertSame(
            ['installed', 'lastHeartbeat', 'healthy'],
            array_keys($payload['updater']),
        );
        self::assertFalse($payload['updater']['installed'], 'no agent has reported in this test');

        self::assertSame('mempalace', $dependency['name']);
        self::assertSame('MemPalace', $dependency['label']);
        self::assertSame('3.9.0', $dependency['installed']);
        self::assertSame('3.10.0', $dependency['latest']);
        self::assertTrue($dependency['updateAvailable'], '3.10.0 is newer than 3.9.0');
        self::assertNull($dependency['checkProblem']);
        self::assertSame('2026-09-13T11:00:00+00:00', $dependency['checkedAt']);
    }

    public function testNoUpdateAvailableWhenTheRunningVersionIsTheNewest(): void
    {
        $this->seed(installed: '3.10.0', latest: '3.10.0', checkedAt: '2026-09-13 11:00:00+00');

        $this->get('/api/admin/dependencies', $this->tokenFor('admin@web-systems.pl'));

        $dependency = $this->json()['dependencies'][0];
        self::assertFalse($dependency['updateAvailable']);
        self::assertNull(
            $dependency['checkProblem'],
            '"nie ma nowszej wersji" is not a problem and must not look like one',
        );
    }

    public function testCheckAnswersWithTheDependencyAndTheAgentState(): void
    {
        // Both halves of the envelope, because the panel needs both to decide what to
        // show and asking twice would let it render a version from one moment against
        // an agent state from another.
        $this->postJson('/api/admin/dependencies/mempalace/check', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();

        $payload = $this->json();
        self::assertSame(['dependency', 'updater'], array_keys($payload));
        self::assertSame('mempalace', $payload['dependency']['name']);
        self::assertNull($payload['dependency']['pendingUpdate']);
        self::assertSame([], $payload['dependency']['history']);
    }

    public function testUnknownDependencyIsNotFound(): void
    {
        $this->postJson('/api/admin/dependencies/redis/check', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnreachableCatalogueReportsAProblemAndKeepsThePreviousLatest(): void
    {
        $this->seed(installed: '3.9.0', latest: '3.10.0', checkedAt: '2026-09-13 11:00:00+00');

        $this->postJson('/api/admin/dependencies/mempalace/check', $this->tokenFor('admin@web-systems.pl'));

        // 200, not 5xx: the request succeeded and its answer is "nie udało się
        // sprawdzić". A 5xx would make a PyPI outage look like a broken backend.
        self::assertResponseIsSuccessful();

        $dependency = $this->json()['dependency'];
        self::assertNotNull($dependency['checkProblem'], 'an unreachable catalogue has to say so');

        self::assertSame(
            '3.10.0',
            $dependency['latest'],
            'a failed check must not erase what the last successful one found',
        );
        self::assertSame(
            '2026-09-13T11:00:00+00:00',
            $dependency['checkedAt'],
            'checkedAt is the last SUCCESSFUL check, so a failure must not move it',
        );

        // And the same has to be true of what was stored, not only of the answer:
        // the next page load reads the row, not this response.
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT latest_version, check_problem FROM ws.dependency_state WHERE name = 'mempalace'"
        );
        self::assertNotFalse($row);
        self::assertSame('3.10.0', $row['latest_version']);
        self::assertNotNull($row['check_problem']);
    }

    public function testCheckingIsRecordedInTheAuditTrail(): void
    {
        $this->postJson('/api/admin/dependencies/mempalace/check', $this->tokenFor('admin@web-systems.pl'));

        $recorded = $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM ws.audit_log WHERE action = 'dependency.checked'"
        );

        self::assertSame(1, (int) $recorded);
    }

    /**
     * Puts a known state in the table, as a successful check would have left it.
     *
     * `pinned_version` is deliberately not seeded: the service reads it from
     * configuration on every request, because it can change with a redeploy
     * without any check having run since.
     */
    private function seed(string $installed, string $latest, string $checkedAt): void
    {
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO ws.dependency_state (name, installed_version, latest_version, checked_at)
                VALUES ('mempalace', :installed, :latest, :checkedAt)
                SQL,
            ['installed' => $installed, 'latest' => $latest, 'checkedAt' => $checkedAt],
        );
    }

    private function tokenFor(string $email): string
    {
        $this->postJson('/api/login', ['email' => $email, 'password' => self::PASSWORD]);

        /** @var string $token */
        $token = $this->json()['token'];

        return $token;
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /**
     * @param array<string, mixed>|string $payloadOrToken a body, or a bearer token for a body-less POST
     */
    private function postJson(string $uri, array|string $payloadOrToken): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        $body = [];

        if (\is_string($payloadOrToken)) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $payloadOrToken;
        } else {
            $body = $payloadOrToken;
        }

        $this->client->request('POST', $uri, server: $server, content: (string) json_encode($body));
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
