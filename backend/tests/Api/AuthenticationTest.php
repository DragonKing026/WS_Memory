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

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

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
        self::assertCount(1, $me['spaces'], 'a fresh account sees exactly its private space');
        self::assertStringStartsWith('priv_', $me['spaces'][0]['slug']);
        self::assertSame('admin', $me['spaces'][0]['role']);
        self::assertTrue($me['spaces'][0]['isPrivate']);
    }

    public function testSigningInIsRecordedInTheAuditLog(): void
    {
        $this->signIn();

        $actions = $this->em->getConnection()->fetchFirstColumn(
            "SELECT action FROM ws.audit_log WHERE action = 'user.login'"
        );

        self::assertNotEmpty($actions, 'every sign-in must leave a trace');
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
