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

/**
 * The accounts screen: who may see it, and the changes it must refuse.
 *
 * The three refusals are the substance. Two of them protect against a state the
 * application cannot be talked out of afterwards — an administrator who took the
 * role off themselves, and an installation with no administrator at all, which has
 * no way left to grant a role or issue an invitation. The third is deactivation
 * failing to stop the account's agents, which would leave a switched-off account
 * still writing to the knowledge base.
 *
 * The listing tests guard the shape the frontend reads and the ceiling on `limit`.
 * An unbounded account listing is the mistake the document listing already made,
 * and it took the backend down on memory for everybody rather than for whoever
 * asked for everything.
 */
final class AdminUserTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $ordinary;
    private User $admin;
    private User $secondAdmin;

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

        $this->ordinary = ($accept)(($issue)('piszacy@web-systems.pl')->plainToken, 'Jan Piszacy', self::PASSWORD);
        $this->admin = ($accept)(
            ($issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Pierwszy Administrator',
            self::PASSWORD,
        );
        // A second administrator, so that "you cannot take the role off yourself"
        // and "you cannot take it off the last administrator" are distinguishable:
        // with only one account holding the role, both rules describe the same
        // click and only the second one says anything useful.
        $this->secondAdmin = ($accept)(
            ($issue)('admin2@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Drugi Administrator',
            self::PASSWORD,
        );
    }

    public function testOrdinaryUserCannotListAccounts(): void
    {
        // The listing is not metadata: it is every address in the company that has
        // access, with the moment each last signed in.
        $this->get('/api/admin/users', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('administratora', $this->json()['error']);
    }

    public function testOrdinaryUserCannotGrantThemselvesTheGlobalRole(): void
    {
        $this->postJson(
            '/api/admin/users/' . $this->ordinary->getId()->toRfc4122() . '/rola-globalna',
            ['admin' => true],
            $this->tokenFor('piszacy@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testOrdinaryUserCannotDeactivateAnybody(): void
    {
        $this->postJson(
            '/api/admin/users/' . $this->admin->getId()->toRfc4122() . '/aktywnosc',
            ['active' => false],
            $this->tokenFor('piszacy@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->client->request('GET', '/api/admin/users');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminSeesTheDocumentedShape(): void
    {
        $this->get('/api/admin/users', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();
        $body = $this->json();

        self::assertSame(3, $body['count']);
        self::assertSame(50, $body['limit'], 'default page size');
        self::assertSame(0, $body['offset']);
        self::assertFalse($body['hasMore']);

        $byEmail = [];
        foreach ($body['users'] as $user) {
            $byEmail[$user['email']] = $user;
        }

        $row = $byEmail['piszacy@web-systems.pl'];
        self::assertSame('Jan Piszacy', $row['displayName']);
        self::assertFalse($row['isGlobalAdmin']);
        self::assertTrue($row['isActive']);
        self::assertNotNull($row['createdAt']);
        // Never signed in through the API in this assertion's path — the field has
        // to be able to say that, and null is the only honest way.
        self::assertArrayHasKey('lastLoginAt', $row);
        // Accepting an invitation creates the private space in the same transaction
        // (inviolable rule 6) and joins the shared space everybody belongs to, so
        // nobody ever has zero — and a fresh account has exactly these two.
        self::assertSame(2, $row['spaceCount']);
        self::assertSame(0, $row['tokenCount']);

        self::assertTrue($byEmail['admin@web-systems.pl']['isGlobalAdmin']);
    }

    public function testAgentTokensAreCountedAndRevokedOnesAreNot(): void
    {
        $issueToken = static::getContainer()->get(IssueAgentToken::class);
        ($issueToken)($this->ordinary, 'Agent testowy');

        $row = $this->rowFor('piszacy@web-systems.pl', $this->tokenFor('admin@web-systems.pl'));
        self::assertSame(1, $row['tokenCount']);

        $this->postJson(
            '/api/admin/users/' . $this->ordinary->getId()->toRfc4122() . '/aktywnosc',
            ['active' => false],
            $this->tokenFor('admin@web-systems.pl'),
        );
        self::assertResponseIsSuccessful();

        // The count answers "does anything automated write as this person right
        // now", which is precisely the question deactivation changes the answer to.
        self::assertSame(0, $this->json()['user']['tokenCount']);
    }

    public function testPaginationIsBoundedAndOffsetMovesTheWindow(): void
    {
        $this->get('/api/admin/users?limit=2', $this->tokenFor('admin@web-systems.pl'));
        self::assertResponseIsSuccessful();

        $firstPage = $this->json();
        self::assertCount(2, $firstPage['users']);
        self::assertSame(2, $firstPage['count']);
        self::assertTrue($firstPage['hasMore'], 'a full page must tell the client to ask again');

        $this->get('/api/admin/users?limit=2&offset=2', $this->tokenFor('admin@web-systems.pl'));
        $secondPage = $this->json();

        self::assertCount(1, $secondPage['users']);
        self::assertFalse($secondPage['hasMore']);
        self::assertNotSame(
            $firstPage['users'][0]['id'],
            $secondPage['users'][0]['id'],
            'offset must move the window, not repeat it',
        );
    }

    public function testLimitIsCappedAndNonsenseFallsBackInsteadOfFailing(): void
    {
        $this->get('/api/admin/users?limit=5000', $this->tokenFor('admin@web-systems.pl'));
        self::assertResponseIsSuccessful();
        self::assertSame(200, $this->json()['limit'], 'the ceiling exists so one client cannot ask for everything');

        $this->get('/api/admin/users?limit=0&offset=-7', $this->tokenFor('admin@web-systems.pl'));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['limit']);
        self::assertSame(0, $this->json()['offset']);
    }

    public function testSearchMatchesAddressAndDisplayName(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->get('/api/admin/users?q=piszacy@', $token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['count']);
        self::assertSame('piszacy@web-systems.pl', $this->json()['users'][0]['email']);

        // The same box has to reach the name; an administrator looking for a person
        // knows what they are called far more often than what they are addressed as.
        $this->get('/api/admin/users?q=Drugi', $token);
        self::assertSame(1, $this->json()['count']);
        self::assertSame('admin2@web-systems.pl', $this->json()['users'][0]['email']);

        $this->get('/api/admin/users?q=Administrator', $token);
        self::assertSame(2, $this->json()['count']);

        $this->get('/api/admin/users?q=nikogo-takiego-nie-ma', $token);
        self::assertSame(0, $this->json()['count']);
        self::assertFalse($this->json()['hasMore']);
    }

    public function testSearchTreatsWildcardsAsTextNotAsPatterns(): void
    {
        // A percent sign typed into a search box is a character somebody wants to
        // find. Unescaped it would match every account, and "_" is worse: it would
        // match one character and the result would look almost right.
        $this->get('/api/admin/users?q=%25', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);
    }

    public function testAdminCannotTakeTheGlobalRoleFromThemselves(): void
    {
        $this->postJson(
            '/api/admin/users/' . $this->admin->getId()->toRfc4122() . '/rola-globalna',
            ['admin' => false],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('sobie', $this->json()['error']);

        $this->em->clear();
        $again = $this->em->getRepository(User::class)->find($this->admin->getId());
        self::assertInstanceOf(User::class, $again);
        self::assertTrue($again->isGlobalAdmin(), 'a refused change must change nothing');
    }

    public function testTheLastAdministratorCannotBeLeftWithoutTheRole(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        // Somebody else's role may go while a second administrator remains.
        $this->postJson(
            '/api/admin/users/' . $this->secondAdmin->getId()->toRfc4122() . '/rola-globalna',
            ['admin' => false],
            $token,
        );
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['user']['isGlobalAdmin']);

        // Now one is left, and this refusal is the one that names the real problem:
        // an installation with no administrator cannot grant the role back or invite
        // anybody, so it cannot be repaired from the application at all.
        $this->postJson(
            '/api/admin/users/' . $this->admin->getId()->toRfc4122() . '/rola-globalna',
            ['admin' => false],
            $token,
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('jedyne aktywne konto administratora', $this->json()['error']);
    }

    public function testDeactivatedAdministratorDoesNotCountAsOneThatIsLeft(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        // Switched off, so unable to sign in — and therefore unable to grant a role
        // or issue an invitation, which is the only thing the guard is protecting.
        $this->postJson(
            '/api/admin/users/' . $this->secondAdmin->getId()->toRfc4122() . '/aktywnosc',
            ['active' => false],
            $token,
        );
        self::assertResponseIsSuccessful();

        $this->postJson(
            '/api/admin/users/' . $this->admin->getId()->toRfc4122() . '/rola-globalna',
            ['admin' => false],
            $token,
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('jedyne aktywne konto administratora', $this->json()['error']);
    }

    public function testAdminCannotDeactivateTheirOwnAccount(): void
    {
        $this->postJson(
            '/api/admin/users/' . $this->admin->getId()->toRfc4122() . '/aktywnosc',
            ['active' => false],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('własnego konta', $this->json()['error']);
    }

    public function testDeactivationRevokesTheAccountsAgentTokens(): void
    {
        $issueToken = static::getContainer()->get(IssueAgentToken::class);
        $first = ($issueToken)($this->ordinary, 'Agent jeden');
        $second = ($issueToken)($this->ordinary, 'Agent dwa');
        // Somebody else's agent, which must survive untouched.
        $foreign = ($issueToken)($this->secondAdmin, 'Agent administratora');

        $this->postJson(
            '/api/admin/users/' . $this->ordinary->getId()->toRfc4122() . '/aktywnosc',
            ['active' => false],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['user']['isActive']);

        // A switched-off account whose agent keeps writing to the knowledge base is
        // switched off only in appearance.
        self::assertNotNull($this->revokedAt($first->tokenId));
        self::assertNotNull($this->revokedAt($second->tokenId));
        self::assertNull($this->revokedAt($foreign->tokenId));
    }

    public function testReactivationDoesNotBringRevokedAgentsBack(): void
    {
        $issueToken = static::getContainer()->get(IssueAgentToken::class);
        $token = ($issueToken)($this->ordinary, 'Agent jeden');
        $adminToken = $this->tokenFor('admin@web-systems.pl');
        $userId = $this->ordinary->getId()->toRfc4122();

        $this->postJson("/api/admin/users/{$userId}/aktywnosc", ['active' => false], $adminToken);
        $revokedAt = $this->revokedAt($token->tokenId);

        $this->postJson("/api/admin/users/{$userId}/aktywnosc", ['active' => true], $adminToken);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['user']['isActive']);

        // Switching an account off and on again must not silently re-arm every agent
        // that was writing before — including the ones nobody remembered existed.
        self::assertSame($revokedAt, $this->revokedAt($token->tokenId));
    }

    public function testGrantingTheGlobalRoleIsRecordedWithWhoDidIt(): void
    {
        $this->postJson(
            '/api/admin/users/' . $this->ordinary->getId()->toRfc4122() . '/rola-globalna',
            ['admin' => true],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['user']['isGlobalAdmin']);

        // D-016: widening somebody's reach never happens silently.
        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id FROM ws.audit_log
             WHERE action = 'user.global_role_granted' ORDER BY created_at DESC LIMIT 1"
        );

        self::assertNotFalse($entry);
        self::assertSame($this->admin->getId()->toRfc4122(), $entry['actor_user_id']);
    }

    public function testDeactivationIsRecorded(): void
    {
        $this->postJson(
            '/api/admin/users/' . $this->ordinary->getId()->toRfc4122() . '/aktywnosc',
            ['active' => false],
            $this->tokenFor('admin@web-systems.pl'),
        );

        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id FROM ws.audit_log
             WHERE action = 'user.deactivated' ORDER BY created_at DESC LIMIT 1"
        );

        self::assertNotFalse($entry);
        self::assertSame($this->admin->getId()->toRfc4122(), $entry['actor_user_id']);
    }

    public function testUnknownAndMalformedIdentifiersAnswerTheSameWay(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/users/0192b8c0-0000-7000-8000-000000000000/rola-globalna', ['admin' => true], $token);
        self::assertResponseStatusCodeSame(404);

        // A value that is not a UUID at all must not reach Doctrine's type
        // conversion, which answers with a 500 rather than a sentence.
        $this->postJson('/api/admin/users/nie-uuid/rola-globalna', ['admin' => true], $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingFlagIsRefusedRatherThanTreatedAsFalse(): void
    {
        // Turning off somebody's account because a key was misspelled is not a
        // mistake anybody would find by reading the response.
        $this->postJson(
            '/api/admin/users/' . $this->ordinary->getId()->toRfc4122() . '/aktywnosc',
            ['aktywne' => false],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $again = $this->em->getRepository(User::class)->find($this->ordinary->getId());
        self::assertInstanceOf(User::class, $again);
        self::assertTrue($again->isActive());
    }

    private function revokedAt(string $tokenId): ?string
    {
        /** @var string|false|null $value */
        $value = $this->em->getConnection()->fetchOne(
            'SELECT revoked_at FROM ws.agent_tokens WHERE id = :id',
            ['id' => $tokenId],
        );

        return \is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $email, string $token): array
    {
        $this->get('/api/admin/users?q=' . urlencode($email), $token);

        /** @var array<string, mixed> $row */
        $row = $this->json()['users'][0];

        return $row;
    }

    private function tokenFor(string $email): string
    {
        $this->postJson('/api/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $this->json()['token'];
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $uri, array $payload, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->client->request(
            'POST',
            $uri,
            server: $server,
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
