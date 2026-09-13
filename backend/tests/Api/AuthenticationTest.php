<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Signing in and finding out who you are.
 *
 * `/api/me` carries the list of spaces, which makes it the frontend's entry
 * point into the permission model: everything the interface shows is decided by
 * what appears here. A space leaking into this response leaks into every screen.
 */
final class AuthenticationTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $sharedSlug;
    private string $sharedRole;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        // From configuration, because that is what decides the answer: the slug and
        // the role of the space every account joins are settings, and the test
        // environment uses a different slug than production on purpose.
        $slug = $container->getParameter('app.default_space.slug');
        $role = $container->getParameter('app.default_space.role');
        self::assertIsString($slug);
        self::assertIsString($role);

        $this->sharedSlug = $slug;
        $this->sharedRole = $role;

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);
        ($accept)(($issue)('pracownik@web-systems.pl')->plainToken, 'Pracownik', self::PASSWORD);
    }

    public function testSigningInReturnsToken(): void
    {
        $this->postJson('/api/login', [
            'email' => 'pracownik@web-systems.pl',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $this->json());
    }

    public function testWrongPasswordIsRefused(): void
    {
        $this->postJson('/api/login', [
            'email' => 'pracownik@web-systems.pl',
            'password' => 'zupelnie-inne-haslo',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAccountIsRefusedTheSameWayAsAWrongPassword(): void
    {
        // Identical answer on purpose: a different one would turn the login
        // form into a tool for checking who works here.
        $this->postJson('/api/login', [
            'email' => 'nie-ma-takiego@web-systems.pl',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeRequiresAToken(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsIdentityAndSpaces(): void
    {
        $token = $this->signIn();

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseIsSuccessful();
        $me = $this->json();

        self::assertSame('pracownik@web-systems.pl', $me['email']);
        self::assertFalse($me['isGlobalAdmin']);

        // Keyed by slug rather than read by position: one of the two entries comes
        // from configuration, so the order they arrive in is not this test's business.
        $bySlug = array_column($me['spaces'], null, 'slug');
        self::assertCount(
            2,
            $bySlug,
            'a fresh account sees its own private space and the shared one, and nothing else',
        );

        $private = 'priv_' . $this->em->getConnection()->fetchOne(
            'SELECT id FROM ws.users WHERE email = :email',
            ['email' => 'pracownik@web-systems.pl'],
        );

        self::assertArrayHasKey($private, $bySlug);
        self::assertTrue($bySlug[$private]['isPrivate']);
        self::assertSame('admin', $bySlug[$private]['role'], 'you administer your own space');

        // The shared space is where the interface has something to show on day one;
        // the role it arrives with decides whether the person can write there.
        self::assertArrayHasKey($this->sharedSlug, $bySlug);
        self::assertFalse($bySlug[$this->sharedSlug]['isPrivate']);
        self::assertSame($this->sharedRole, $bySlug[$this->sharedSlug]['role']);
    }

    public function testSigningInIsRecordedInTheAuditLog(): void
    {
        $this->signIn();

        $actions = $this->em->getConnection()->fetchFirstColumn(
            "SELECT action FROM ws.audit_log WHERE action = 'user.login'"
        );

        self::assertNotEmpty($actions, 'every sign-in must leave a trace');
    }

    /**
     * One sign-in leaves one entry, no matter how many requests follow it.
     *
     * The firewalls are stateless, so the bearer token is re-authenticated on every
     * single request and `LoginSuccessEvent` fires each time. Before this was guarded,
     * clicking around the application wrote a "user.login" row per HTTP request: the
     * audit screen, the first time anybody opened it, held 40 889 entries of which
     * 20 335 were sign-ins that never happened.
     *
     * The test that existed asserted the log was *not empty*, which is true of both
     * the working and the broken version — which is why it caught nothing. This one
     * counts.
     */
    public function testAuthenticatedRequestsDoNotEachCountAsASignIn(): void
    {
        $token = $this->signIn();

        foreach (['/api/me', '/api/me', '/api/spaces'] as $path) {
            $this->client->request('GET', $path, server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ]);
        }

        $entries = (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM ws.audit_log WHERE action = 'user.login'"
        );

        self::assertSame(1, $entries, 'presenting a token again is not signing in again');
    }

    public function testDeactivatedAccountLosesAccessImmediately(): void
    {
        $token = $this->signIn();

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseIsSuccessful();

        // Deactivation must bite at once. A token issued a minute ago is still
        // cryptographically valid, so without an explicit check a dismissed
        // employee keeps reading the knowledge base until it expires.
        $this->em->getConnection()->executeStatement(
            "UPDATE ws.users SET is_active = false WHERE email = 'pracownik@web-systems.pl'"
        );
        $this->em->clear();

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(401, 'a valid token must not outlive an active account');
    }

    public function testDeactivatedAccountCannotSignInAgain(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE ws.users SET is_active = false WHERE email = 'pracownik@web-systems.pl'"
        );
        // The client shares this kernel, so the entity from setUp is still in
        // the identity map and would be handed to the firewall as active.
        // Clearing it makes the test observe the database, not the test's own
        // leftovers.
        $this->em->clear();

        $this->postJson('/api/login', [
            'email' => 'pracownik@web-systems.pl',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private function signIn(): string
    {
        $this->postJson('/api/login', [
            'email' => 'pracownik@web-systems.pl',
            'password' => self::PASSWORD,
        ]);

        return $this->json()['token'];
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
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
