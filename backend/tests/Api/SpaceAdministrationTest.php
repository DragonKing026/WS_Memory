<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who may create spaces and hand out roles.
 *
 * Granting a role is the one operation that widens somebody else's reach, so
 * the negative cases here are the substance: an ordinary member must not be
 * able to promote themselves, and a global administrator must leave a trace
 * when they grant themselves access (D-016).
 */
final class SpaceAdministrationTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $spaceAdmin;
    private User $writer;
    private User $globalAdmin;

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

        $this->spaceAdmin = ($accept)(($issue)('szef@web-systems.pl')->plainToken, 'Szef', self::PASSWORD);
        $this->writer = ($accept)(($issue)('piszacy@web-systems.pl')->plainToken, 'Piszący', self::PASSWORD);
        $this->globalAdmin = ($accept)(
            ($issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Administrator',
            self::PASSWORD,
        );

        $space = new Space('alfa', 'główna aplikacja Symfony zespołu');
        $this->em->persist($space);
        $this->em->persist(new SpaceMember($space, $this->spaceAdmin, SpaceRole::Admin));
        $this->em->persist(new SpaceMember($space, $this->writer, SpaceRole::Writer));
        $this->em->flush();
    }

    public function testWriterCannotAddMembers(): void
    {
        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'writer',
        ], $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403, 'writing content is not administering access');
    }

    public function testWriterCannotPromoteThemselves(): void
    {
        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'piszacy@web-systems.pl',
            'role' => 'admin',
        ], $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testStrangerAddingMembersGetsNotFoundNotForbidden(): void
    {
        // A stranger must not learn the space exists, even by being refused.
        $stranger = static::getContainer()->get(AcceptInvitation::class)(
            static::getContainer()->get(IssueInvitation::class)('obcy@web-systems.pl')->plainToken,
            'Obcy',
            self::PASSWORD,
        );
        self::assertInstanceOf(User::class, $stranger);

        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'obcy@web-systems.pl',
            'role' => 'admin',
        ], $this->tokenFor('obcy@web-systems.pl'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSpaceAdminCanAddMember(): void
    {
        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'reader',
        ], $this->tokenFor('szef@web-systems.pl'));

        self::assertResponseIsSuccessful();

        $this->get('/api/spaces/alfa', $this->tokenFor('admin@web-systems.pl'));
        self::assertResponseIsSuccessful();
        self::assertSame('reader', $this->json()['role']);
    }

    public function testGrantingRoleIsRecordedWithTheGranter(): void
    {
        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'reader',
        ], $this->tokenFor('szef@web-systems.pl'));

        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id, space_slug FROM ws.audit_log
             WHERE action = 'space.member_added' ORDER BY created_at DESC LIMIT 1"
        );

        self::assertNotFalse($entry);
        self::assertSame($this->spaceAdmin->getId()->toRfc4122(), $entry['actor_user_id']);
        self::assertSame('alfa', $entry['space_slug']);
    }

    public function testGlobalAdminGrantingThemselvesAccessLeavesATrace(): void
    {
        // D-016: an administrator may reach into a space, but never silently.
        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'admin',
        ], $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();

        $trace = $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM ws.audit_log
             WHERE action = 'space.member_added' AND actor_user_id = :id",
            ['id' => $this->globalAdmin->getId()->toRfc4122()],
        );

        self::assertSame(1, (int) $trace);
    }

    public function testOrdinaryUserCannotCreateSpaces(): void
    {
        $this->postJson('/api/spaces', [
            'slug' => 'nowa',
            'name' => 'Nowa przestrzeń',
        ], $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGlobalAdminCreatesSpaceAndBecomesItsAdministrator(): void
    {
        $this->postJson('/api/spaces', [
            'slug' => 'nowa',
            'name' => 'Nowa przestrzeń',
        ], $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();

        // Whoever creates a space must be able to run it; otherwise the first
        // action after creating one is to grant yourself access to it.
        $this->get('/api/spaces/nowa', $this->tokenFor('admin@web-systems.pl'));
        self::assertResponseIsSuccessful();
        self::assertSame('admin', $this->json()['role']);
    }

    public function testPrivateSpaceCannotBeSharedWithAnybody(): void
    {
        $privateSlug = 'priv_' . $this->writer->getId()->toRfc4122();

        $this->postJson("/api/spaces/{$privateSlug}/members", [
            'email' => 'szef@web-systems.pl',
            'role' => 'reader',
        ], $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(
            403,
            'a private space stops being private the moment it can be shared',
        );
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
