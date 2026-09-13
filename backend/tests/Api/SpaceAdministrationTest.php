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

        $space = new Space('alfa', 'Alfa');
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

        // Narrowed to alfa, because "the newest space.member_added" no longer names
        // this grant. Creating the three accounts in setUp admits each of them to the
        // shared space, which writes an actorless entry of the same action, and
        // `created_at` is a TIMESTAMP(0) — within one second the rows tie and the
        // ordering picks whichever it likes.
        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id, space_slug FROM ws.audit_log
             WHERE action = 'space.member_added' AND space_slug = 'alfa'
             ORDER BY created_at DESC LIMIT 1"
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

        // 422, not 403, and the same code the administration route gives for this rule.
        // No role unlocks it, so it is not a permission problem — it is a request that
        // cannot mean anything. One rule answering with two codes made every client
        // learn both, which is how the two routes drifted apart in the first place.
        self::assertResponseStatusCodeSame(
            422,
            'a private space stops being private the moment it can be shared',
        );
    }

    /**
     * Letting somebody in and promoting somebody already inside are different events.
     *
     * This route accepts both, because a client should not have to know which case it
     * is in — but the audit log must, or D-016 cannot answer the question it exists
     * for: did that person gain access then, or did they already have it?
     */
    public function testPromotingAnExistingMemberIsNotRecordedAsLettingThemIn(): void
    {
        $token = $this->tokenFor('szef@web-systems.pl');

        // `admin@web-systems.pl` is not in `alfa` yet, so the first call lets them in
        // and the second only changes what they may do.
        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'reader',
        ], $token);
        self::assertResponseIsSuccessful();

        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'writer',
        ], $token);
        self::assertResponseIsSuccessful();

        $actions = $this->em->getConnection()->fetchFirstColumn(
            "SELECT action FROM ws.audit_log WHERE space_slug = 'alfa' "
            . "AND action LIKE 'space.member%' ORDER BY created_at"
        );

        self::assertSame(['space.member_added', 'space.member_role_changed'], $actions);
    }

    /**
     * Granting somebody the role they already hold changes nothing, so it records nothing.
     *
     * The request still succeeds — repeating it is not an error, and a client should be
     * able to state the role it wants without first asking what it is. What must not
     * happen is the entry: `space.member_role_changed` with `previousRole` equal to the
     * new role is a log line describing a change that did not occur. This system has
     * already paid for that habit once, with 20 335 `user.login` rows written per
     * request instead of per sign-in.
     */
    public function testGrantingTheRoleSomebodyAlreadyHasRecordsNothing(): void
    {
        $token = $this->tokenFor('szef@web-systems.pl');

        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'reader',
        ], $token);
        self::assertResponseIsSuccessful();

        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'reader',
        ], $token);
        self::assertResponseIsSuccessful('repeating a grant is not an error');

        $actions = $this->em->getConnection()->fetchFirstColumn(
            "SELECT action FROM ws.audit_log WHERE space_slug = 'alfa' "
            . "AND action LIKE 'space.member%' ORDER BY created_at"
        );

        self::assertSame(['space.member_added'], $actions);
    }

    /**
     * A switched-off account cannot be given access, because giving it changes nothing.
     *
     * The grant would succeed, the person still could not sign in, and the trail would
     * carry an entry saying they were let in — the opposite of what happened.
     */
    public function testAccessCannotBeGrantedToADeactivatedAccount(): void
    {
        $this->deactivate('admin@web-systems.pl');

        $this->postJson('/api/spaces/alfa/members', [
            'email' => 'admin@web-systems.pl',
            'role' => 'reader',
        ], $this->tokenFor('szef@web-systems.pl'));

        self::assertResponseStatusCodeSame(422);
    }

    /** Switches an account off through the entity, the same way the console does. */
    private function deactivate(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $user->deactivate();
        $this->em->flush();
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
