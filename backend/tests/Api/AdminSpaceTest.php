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
use Symfony\Component\Uid\Uuid;

/**
 * The spaces administration screen.
 *
 * Three groups of tests, and the middle one is the substance.
 *
 * The counters come first because they are the reason the listing exists: an
 * administrator opens this screen to find the space nobody is in and the space nobody
 * writes to. A counter that is merely plausible is worse than none, so they are checked
 * against a fixture where the numbers differ from each other and from the number of rows
 * in the table — two members against three documents of which one is archived.
 *
 * Then the two refusals. A space whose last administrator can be demoted has nobody who
 * can hand out roles in it, and a private space whose membership can be edited is not
 * private. Both are enforced in SpaceMemberAdministration rather than in the controller,
 * so both are tested through HTTP where a future second caller would reach them.
 *
 * And the access rule, on the read half and the write half alike: a surface where one half
 * is guarded invites the next endpoint to be added to the other.
 */
final class AdminSpaceTest extends WebTestCase
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
            'TRUNCATE ws.messenger_messages, ws.proposals, ws.memory_entries, ws.document_revisions, '
            . 'ws.documents, ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, '
            . 'ws.spaces, ws.users CASCADE'
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

        // Accepting an invitation creates a private space per account, so the installation
        // already holds three of them. They are part of what this screen must show.
        $alfa = new Space('alfa', 'Alfa');
        $alfa->setDescription('Przestrzeń zespołowa');
        $beta = new Space('beta', 'Beta');

        $this->em->persist($alfa);
        $this->em->persist($beta);
        $this->em->persist(new SpaceMember($alfa, $this->spaceAdmin, SpaceRole::Admin));
        $this->em->persist(new SpaceMember($alfa, $this->writer, SpaceRole::Writer, $this->globalAdmin));
        // Beta has exactly one administrator, which is the fixture the refusals need.
        $this->em->persist(new SpaceMember($beta, $this->spaceAdmin, SpaceRole::Admin));
        $this->em->flush();

        $this->seedDocuments($alfa->getId()->toRfc4122());
    }

    public function testWriterCannotSeeTheSpaceList(): void
    {
        $this->get('/api/admin/spaces', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('administratora', $this->json()['error']);
    }

    public function testSpaceAdminIsNotAGlobalAdmin(): void
    {
        // Administering one space says nothing about seeing every space. The distinction
        // is the whole reason this surface is separate from /api/spaces.
        $this->get('/api/admin/spaces', $this->tokenFor('szef@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testWriterCannotChangeRoles(): void
    {
        $this->putJson(
            '/api/admin/spaces/alfa/members/' . $this->writer->getId()->toRfc4122(),
            ['role' => 'admin'],
            $this->tokenFor('piszacy@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $this->client->request('GET', '/api/admin/spaces');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminSeesTheDocumentedShapeWithTruthfulCounters(): void
    {
        $this->get('/api/admin/spaces', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = $this->json();
        self::assertSame(['spaces', 'count', 'limit', 'offset', 'hasMore'], array_keys($payload));
        // Two shared spaces plus one private space per account.
        self::assertSame(5, $payload['count']);
        self::assertFalse($payload['hasMore']);

        $alfa = $payload['spaces'][0];
        self::assertSame(
            [
                'slug', 'name', 'description', 'isPrivate', 'requiresProposal', 'palaceWing',
                'memberCount', 'documentCount', 'createdAt',
            ],
            array_keys($alfa),
            'the frontend reads these keys by name; a nullable one must be present, not absent',
        );

        self::assertSame('alfa', $alfa['slug']);
        self::assertSame('Przestrzeń zespołowa', $alfa['description']);
        self::assertFalse($alfa['isPrivate']);
        self::assertSame(2, $alfa['memberCount'], 'szef and piszacy');
        // Three document rows, one of them archived: an archived document is content
        // somebody retired, and counting it would report a space as busier than it is.
        self::assertSame(2, $alfa['documentCount']);

        $beta = $payload['spaces'][1];
        self::assertSame('beta', $beta['slug']);
        self::assertNull($beta['description']);
        self::assertSame(1, $beta['memberCount']);
        self::assertSame(0, $beta['documentCount'], 'an empty space reports zero, not null');
    }

    public function testPrivateSpacesAreListedWithTheirFlag(): void
    {
        $this->get('/api/admin/spaces', $this->tokenFor('admin@web-systems.pl'));

        $private = array_values(array_filter(
            $this->json()['spaces'],
            static fn (array $space): bool => true === $space['isPrivate'],
        ));

        // Hidden, they would be invisible to the one person who has to know they exist:
        // they hold content, count against storage and belong to accounts that may leave.
        self::assertCount(3, $private);
        self::assertSame(1, $private[0]['memberCount'], 'a private space holds one person');
    }

    public function testPagingSlicesTheListAndTheLimitIsCapped(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        $this->get('/api/admin/spaces?limit=2', $token);
        $first = $this->json();
        self::assertCount(2, $first['spaces']);
        self::assertSame(5, $first['count'], 'count is the whole list, not this page');
        self::assertTrue($first['hasMore']);

        $this->get('/api/admin/spaces?limit=2&offset=4', $token);
        $last = $this->json();
        self::assertCount(1, $last['spaces']);
        self::assertFalse($last['hasMore']);
        self::assertNotSame(
            $first['spaces'][0]['slug'],
            $last['spaces'][0]['slug'],
            'a stable order is what makes paging mean anything',
        );

        $this->get('/api/admin/spaces?limit=100000', $token);
        self::assertSame(200, $this->json()['limit'], 'the ceiling is answered, not silently ignored');
    }

    public function testMembersAreListedWithTheGranterNamed(): void
    {
        $this->get('/api/admin/spaces/alfa/members', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseIsSuccessful();
        $members = $this->json()['members'];
        self::assertCount(2, $members);

        self::assertSame(
            ['userId', 'displayName', 'email', 'role', 'addedAt', 'addedBy'],
            array_keys($members[0]),
        );

        // Administrators first: the panel's question is who runs this space.
        self::assertSame('admin', $members[0]['role']);
        self::assertSame('Szef', $members[0]['displayName']);
        self::assertNull($members[0]['addedBy'], 'nobody recorded as the granter stays null');

        self::assertSame('writer', $members[1]['role']);
        self::assertSame('piszacy@web-systems.pl', $members[1]['email']);
        self::assertSame(
            'Administrator',
            $members[1]['addedBy'],
            'a name, not an id: "who let this person in" is not answered by a UUID',
        );
    }

    public function testMembersOfUnknownSpaceIsNotFound(): void
    {
        $this->get('/api/admin/spaces/nie-ma-takiej/members', $this->tokenFor('admin@web-systems.pl'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testMembershipOfAPrivateSpaceCanBeRead(): void
    {
        $this->get($this->privateMembersUri(), $this->tokenFor('admin@web-systems.pl'));

        // Reading is allowed where changing is not: the one member is the owner, whose id
        // the slug already carries, so refusing would hide nothing from anybody.
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['members']);
    }

    public function testRoleChangeIsAppliedAndAudited(): void
    {
        $this->putJson(
            '/api/admin/spaces/alfa/members/' . $this->writer->getId()->toRfc4122(),
            ['role' => 'reader'],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('reader', $this->json()['role']);

        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id, space_slug, target FROM ws.audit_log
             WHERE action = 'space.member_role_changed' ORDER BY created_at DESC LIMIT 1"
        );

        self::assertNotFalse($entry, 'D-016: an administrator reaching into a space leaves a trace');
        self::assertSame($this->globalAdmin->getId()->toRfc4122(), $entry['actor_user_id']);
        self::assertSame('alfa', $entry['space_slug']);

        $target = json_decode((string) $entry['target'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($target);
        self::assertSame('reader', $target['role']);
        self::assertSame(
            'writer',
            $target['previousRole'],
            'without the previous role the log cannot answer whether this was a promotion',
        );
    }

    public function testUnknownRoleIsRefused(): void
    {
        $this->putJson(
            '/api/admin/spaces/alfa/members/' . $this->writer->getId()->toRfc4122(),
            ['role' => 'wlasciciel'],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('reader, writer, admin', $this->json()['error']);
    }

    public function testUnknownMemberIsNotFound(): void
    {
        $this->putJson(
            '/api/admin/spaces/alfa/members/' . Uuid::v7()->toRfc4122(),
            ['role' => 'reader'],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testLastAdministratorCannotBeDemoted(): void
    {
        $this->putJson(
            '/api/admin/spaces/beta/members/' . $this->spaceAdmin->getId()->toRfc4122(),
            ['role' => 'reader'],
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('ostatni administrator', $this->json()['error']);
        self::assertSame('admin', $this->roleIn('beta', $this->spaceAdmin), 'the refusal wrote nothing');
    }

    public function testLastAdministratorCannotBeRemoved(): void
    {
        $this->delete(
            '/api/admin/spaces/beta/members/' . $this->spaceAdmin->getId()->toRfc4122(),
            $this->tokenFor('admin@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame('admin', $this->roleIn('beta', $this->spaceAdmin));
    }

    public function testAdministratorIsRemovableOnceAnotherOneExists(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');

        // The way out of the refusal, and the reason it is not a dead end: appoint
        // somebody else first.
        $this->putJson(
            '/api/admin/spaces/alfa/members/' . $this->writer->getId()->toRfc4122(),
            ['role' => 'admin'],
            $token,
        );
        self::assertResponseIsSuccessful();

        $this->delete('/api/admin/spaces/alfa/members/' . $this->spaceAdmin->getId()->toRfc4122(), $token);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->roleIn('alfa', $this->spaceAdmin));
        self::assertSame('admin', $this->roleIn('alfa', $this->writer));
    }

    public function testMembershipOfAPrivateSpaceCannotBeChanged(): void
    {
        $token = $this->tokenFor('admin@web-systems.pl');
        $uri = $this->privateMembersUri() . '/' . $this->writer->getId()->toRfc4122();

        $this->putJson($uri, ['role' => 'reader'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('prywatna', $this->json()['error']);

        $this->delete($uri, $token);
        self::assertResponseStatusCodeSame(
            422,
            'a private space stops being private the moment its membership is editable',
        );
    }

    /**
     * Documents inserted as rows rather than through DocumentService.
     *
     * What is being tested is a counting query, and a document written through the service
     * would drag a revision, a publication message and an embedding into a test about two
     * numbers.
     */
    private function seedDocuments(string $spaceId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ([['zywy-1', null], ['zywy-2', null], ['zarchiwizowany', $now]] as [$slug, $archivedAt]) {
            $this->em->getConnection()->insert('ws.documents', [
                'id' => Uuid::v7()->toRfc4122(),
                'space_id' => $spaceId,
                'slug' => $slug,
                'title' => $slug,
                'status' => 'published',
                'authored_by_ai' => 'false',
                'created_at' => $now,
                'updated_at' => $now,
                'archived_at' => $archivedAt,
            ]);
        }
    }

    private function privateMembersUri(): string
    {
        return '/api/admin/spaces/priv_' . $this->writer->getId()->toRfc4122() . '/members';
    }

    private function roleIn(string $slug, User $user): ?string
    {
        $role = $this->em->getConnection()->fetchOne(
            'SELECT m.role FROM ws.space_members m
             JOIN ws.spaces s ON s.id = m.space_id
             WHERE s.slug = :slug AND m.user_id = :user',
            ['slug' => $slug, 'user' => $user->getId()->toRfc4122()],
        );

        return false === $role ? null : (string) $role;
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
    private function putJson(string $uri, array $payload, string $token): void
    {
        $this->client->request(
            'PUT',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
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
