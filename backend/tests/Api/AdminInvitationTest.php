<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The invitations screen.
 *
 * Two properties here are worth more than the rest put together.
 *
 * The plain token appears in the answer to the request that issued it and nowhere
 * else — the table holds only its sha256, exactly as with agent tokens — so the
 * listing cannot hand out a working link and neither can any future endpoint that
 * reads these rows. The test below asserts both halves: the response carries the
 * token, and the database does not.
 *
 * `status` is derived on every read. There is no column to hold it and there must
 * not be one, because expiry happens because time passed, with nobody around to run
 * an UPDATE — a stored status would be wrong for every invitation nobody looked at.
 */
final class AdminInvitationTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;

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

        ($accept)(($issue)('piszacy@web-systems.pl')->plainToken, 'Jan Piszacy', self::PASSWORD);
        $this->admin = ($accept)(
            ($issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Artur Ograbek',
            self::PASSWORD,
        );
    }

    public function testOrdinaryUserCannotListInvitations(): void
    {
        $this->get('/api/admin/invitations', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('administratora', $this->json()['error']);
    }

    public function testOrdinaryUserCannotIssueInvitations(): void
    {
        // Issuing an invitation widens the set of people who can read this company's
        // knowledge base; with `admin: true` it widens the set who can widen it.
        $this->postJson('/api/admin/invitations', [
            'email' => 'obcy@web-systems.pl',
        ], $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                "SELECT count(*) FROM ws.invitations WHERE email = 'obcy@web-systems.pl'"
            ),
        );
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->client->request('GET', '/api/admin/invitations');

        self::assertResponseStatusCodeSame(401);
    }

    public function testIssuingReturnsThePlainTokenWhileTheDatabaseKeepsOnlyItsHash(): void
    {
        $this->postJson('/api/admin/invitations', [
            'email' => 'nowy@web-systems.pl',
            'admin' => false,
        ], $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();

        self::assertSame('nowy@web-systems.pl', $body['invitation']['email']);
        self::assertSame('oczekuje', $body['invitation']['status']);
        self::assertSame('Artur Ograbek', $body['invitation']['invitedBy']);
        self::assertFalse($body['invitation']['grantsGlobalAdmin']);
        self::assertNull($body['invitation']['acceptedAt']);

        self::assertMatchesRegularExpression('#^/zaproszenie/[0-9a-f]{64}$#', $body['link']);
        $plainToken = substr($body['link'], \strlen('/zaproszenie/'));

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT token_hash FROM ws.invitations WHERE email = :email',
            ['email' => 'nowy@web-systems.pl'],
        );

        // The whole property in two assertions: what is stored is the hash, and the
        // plain value is not anywhere in that column. There is no endpoint that can
        // produce this link a second time, and there must never be one.
        self::assertSame(hash('sha256', $plainToken), $stored);
        self::assertNotSame($plainToken, $stored);

        // Nor may it have been written into the audit trail "for convenience": the
        // trail is read by more people than the response is.
        $trail = (string) $this->em->getConnection()->fetchOne(
            "SELECT coalesce(string_agg(target::text, ' '), '') FROM ws.audit_log"
        );
        self::assertStringNotContainsString($plainToken, $trail);
    }

    public function testTheTokenFromTheResponseActuallyWorks(): void
    {
        // A link shown once is worthless if it is not the link that opens the door.
        $this->postJson('/api/admin/invitations', [
            'email' => 'nowy@web-systems.pl',
        ], $this->tokenFor('admin@web-systems.pl'));

        $plainToken = substr($this->json()['link'], \strlen('/zaproszenie/'));

        $user = static::getContainer()->get(AcceptInvitation::class)(
            $plainToken,
            'Nowy Ktos',
            self::PASSWORD,
        );

        self::assertSame('nowy@web-systems.pl', $user->getEmail());
    }

    public function testAnInvitationCanGrantTheGlobalRole(): void
    {
        $this->postJson('/api/admin/invitations', [
            'email' => 'przyszly-admin@web-systems.pl',
            'admin' => true,
        ], $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->json()['invitation']['grantsGlobalAdmin']);

        // D-016: this is the operation that widens who may widen access, so the
        // trail has to name who issued it.
        // Matched on the address rather than on "the newest entry": `created_at` has
        // second precision, and the fixtures issue their invitations in the same
        // second as this one, so ORDER BY would pick among them at random.
        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id FROM ws.audit_log
             WHERE action = 'invitation.issued' AND target->>'email' = :email",
            ['email' => 'przyszly-admin@web-systems.pl'],
        );

        self::assertNotFalse($entry);
        self::assertSame($this->admin->getId()->toRfc4122(), $entry['actor_user_id']);
    }

    public function testASecondOutstandingInvitationToTheSameAddressIsRefused(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        self::assertResponseStatusCodeSame(201);

        // Two working tokens for one account, of which only one can ever be used:
        // whoever pasted the older link would be told their invitation is invalid
        // with no way to find out why.
        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('już wystawione zaproszenie', $this->json()['error']);

        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                "SELECT count(*) FROM ws.invitations WHERE email = 'nowy@web-systems.pl'"
            ),
        );
    }

    public function testAnInvitationForAnExistingAccountIsRefused(): void
    {
        $this->postJson('/api/admin/invitations', [
            'email' => 'piszacy@web-systems.pl',
        ], $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('już istnieje', $this->json()['error']);
    }

    public function testAnExpiredInvitationDoesNotBlockANewOne(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        $this->expire('nowy@web-systems.pl');

        // It grants nothing any more, so refusing here would leave an address that
        // can never be invited again.
        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);

        self::assertResponseStatusCodeSame(201);
    }

    public function testAnAddressThatIsNotOneIsRefusedAsUnprocessable(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        // 422, not 409: the caller has to change what they sent, not wait.
        foreach (['nie-adres', 'bez@domeny', '@web-systems.pl', ''] as $nonsense) {
            $this->postJson('/api/admin/invitations', ['email' => $nonsense], $token);

            self::assertResponseStatusCodeSame(422, "„{$nonsense}” is not an address");
        }
    }

    public function testAdminFlagMustBeABooleanRatherThanAnythingTruthy(): void
    {
        // "false" as a string is true in PHP, and the mistake would hand out the
        // role that hands out roles.
        $this->postJson('/api/admin/invitations', [
            'email' => 'nowy@web-systems.pl',
            'admin' => 'false',
        ], $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(422);
    }

    public function testStatusIsDerivedFromTheTwoTimestamps(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'oczekujacy@web-systems.pl'], $token);
        $this->postJson('/api/admin/invitations', ['email' => 'wygasly@web-systems.pl'], $token);
        $this->expire('wygasly@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'przyjety@web-systems.pl'], $token);
        static::getContainer()->get(AcceptInvitation::class)(
            substr($this->json()['link'], \strlen('/zaproszenie/')),
            'Przyjety Ktos',
            self::PASSWORD,
        );

        $this->get('/api/admin/invitations', $token);
        self::assertResponseIsSuccessful();

        $status = [];
        foreach ($this->json()['invitations'] as $invitation) {
            $status[$invitation['email']] = $invitation['status'];
        }

        self::assertSame('oczekuje', $status['oczekujacy@web-systems.pl']);
        self::assertSame('wygasle', $status['wygasly@web-systems.pl']);
        self::assertSame('przyjete', $status['przyjety@web-systems.pl']);
        // The two invitations the fixtures accepted before any of this.
        self::assertSame('przyjete', $status['admin@web-systems.pl']);
    }

    public function testAnAcceptedInvitationStaysAcceptedAfterItsExpiryDate(): void
    {
        // Acceptance wins over expiry. Showing it as expired would suggest to an
        // administrator that the account was never created.
        $this->expire('piszacy@web-systems.pl');

        $this->get('/api/admin/invitations', $this->tokenFor('admin@web-systems.pl'));

        $status = [];
        foreach ($this->json()['invitations'] as $invitation) {
            $status[$invitation['email']] = $invitation['status'];
        }

        self::assertSame('przyjete', $status['piszacy@web-systems.pl']);
    }

    public function testListingIsPagedAndBounded(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->get('/api/admin/invitations', $token);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['count'], 'the two invitations the fixtures accepted');
        self::assertSame(50, $this->json()['limit']);
        self::assertFalse($this->json()['hasMore']);

        $this->get('/api/admin/invitations?limit=1', $token);
        self::assertCount(1, $this->json()['invitations']);
        self::assertTrue($this->json()['hasMore']);

        $this->get('/api/admin/invitations?limit=9000', $token);
        self::assertSame(200, $this->json()['limit']);
    }

    public function testTheListingNeverCarriesATokenOrItsHash(): void
    {
        $this->get('/api/admin/invitations', $this->tokenFor('admin@web-systems.pl'));

        foreach ($this->json()['invitations'] as $invitation) {
            self::assertSame(
                ['id', 'email', 'invitedBy', 'grantsGlobalAdmin', 'status', 'createdAt', 'expiresAt', 'acceptedAt'],
                array_keys($invitation),
                'a listing that never holds a secret cannot leak one',
            );
        }
    }

    public function testAPendingInvitationCanBeRevoked(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        $id = $this->json()['invitation']['id'];
        $plainToken = substr($this->json()['link'], \strlen('/zaproszenie/'));

        $this->delete("/api/admin/invitations/{$id}", $token);
        self::assertResponseStatusCodeSame(204);

        // Revoked means the link stops working, not merely that it stops being listed.
        $this->expectException(\DomainException::class);
        static::getContainer()->get(AcceptInvitation::class)($plainToken, 'Nowy Ktos', self::PASSWORD);
    }

    public function testRevokingIsRecordedWithTheAddressItWasFor(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        $this->delete('/api/admin/invitations/' . $this->json()['invitation']['id'], $token);

        // The row is gone, so the trail is the only place left that says who this
        // invitation was for and who withdrew it.
        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id, target::text AS target FROM ws.audit_log
             WHERE action = 'invitation.revoked' ORDER BY created_at DESC LIMIT 1"
        );

        self::assertNotFalse($entry);
        self::assertSame($this->admin->getId()->toRfc4122(), $entry['actor_user_id']);
        self::assertStringContainsString('nowy@web-systems.pl', (string) $entry['target']);
    }

    public function testRevokingAnInvitationFreesTheAddressForANewOne(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        $this->delete('/api/admin/invitations/' . $this->json()['invitation']['id'], $token);

        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAnAcceptedInvitationCannotBeRevoked(): void
    {
        $id = (string) $this->em->getConnection()->fetchOne(
            "SELECT id FROM ws.invitations WHERE email = 'piszacy@web-systems.pl'"
        );

        $this->delete("/api/admin/invitations/{$id}", $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(409);
        // Deleting the row would take away nobody's access while destroying the only
        // record of who let them in.
        self::assertStringContainsString('wyłącz konto', $this->json()['error']);
    }

    public function testOrdinaryUserCannotRevokeInvitations(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');
        $this->postJson('/api/admin/invitations', ['email' => 'nowy@web-systems.pl'], $token);
        $id = $this->json()['invitation']['id'];

        $this->delete("/api/admin/invitations/{$id}", $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownAndMalformedInvitationIdentifiersAnswerTheSameWay(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->delete('/api/admin/invitations/0192b8c0-0000-7000-8000-000000000000', $token);
        self::assertResponseStatusCodeSame(404);

        $this->delete('/api/admin/invitations/nie-uuid', $token);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Moves an invitation's expiry into the past.
     *
     * Done in SQL because time is the only thing that makes an invitation expire and
     * the test cannot wait seven days for it.
     */
    private function expire(string $email): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE ws.invitations SET expires_at = now() - interval '1 day' WHERE email = :email",
            ['email' => $email],
        );
        $this->em->clear();
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

    private function delete(string $uri, string $token): void
    {
        $this->client->request('DELETE', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
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
