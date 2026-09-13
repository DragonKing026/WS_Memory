<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Application\Invitation\SharedSpaceForEveryone;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every account belongs to the shared space from the moment it exists.
 *
 * Before this, a new person signed in and read "you do not belong to any team
 * space yet" — the knowledge base was there, they simply could not see it, and
 * somebody with the administrator role had to finish the job by hand for each
 * person. What is asserted here is that nobody has to.
 */
final class SharedSpaceForEveryoneTest extends KernelTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private EntityManagerInterface $em;
    private IssueInvitation $issue;
    private AcceptInvitation $accept;
    private string $sharedSlug;
    private string $sharedRole;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Read from configuration rather than written down a second time. The slug
        // is deliberately not the same in the test environment as in production, so
        // a literal here would assert one installation's fixture instead of the rule
        // under test: every account joins the space that is configured.
        $slug = $container->getParameter('app.default_space.slug');
        $role = $container->getParameter('app.default_space.role');
        self::assertIsString($slug);
        self::assertIsString($role);

        $this->sharedSlug = $slug;
        $this->sharedRole = $role;

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->issue = $container->get(IssueInvitation::class);
        $this->accept = $container->get(AcceptInvitation::class);
    }

    public function testNewAccountLandsInTheSharedSpaceWithoutAnybodyAddingIt(): void
    {
        $user = ($this->accept)(($this->issue)('nowy@web-systems.pl')->plainToken, 'Nowy', self::PASSWORD);

        $roles = $this->teamRolesOf($user);

        self::assertSame(
            [$this->sharedSlug => $this->sharedRole],
            $roles,
            'an account with no team space is an account somebody has to finish creating',
        );
    }

    public function testTheSharedSpaceIsCreatedOnceEvenThoughEveryAccountJoinsIt(): void
    {
        ($this->accept)(($this->issue)('pierwszy@web-systems.pl')->plainToken, 'Pierwszy', self::PASSWORD);
        ($this->accept)(($this->issue)('drugi@web-systems.pl')->plainToken, 'Drugi', self::PASSWORD);

        $spaces = $this->em->getConnection()->fetchFirstColumn(
            "SELECT slug FROM ws.spaces WHERE slug NOT LIKE 'priv\\_%'"
        );

        self::assertSame([$this->sharedSlug], $spaces);
    }

    /**
     * The trail says the rule admitted them, not that somebody did.
     *
     * Naming the new account as the actor would read as "they let themselves in",
     * and naming the inviter is impossible anyway: an invitation issued from the
     * console has no inviter at all.
     */
    public function testJoiningTheSharedSpaceIsRecordedWithNoActor(): void
    {
        ($this->accept)(($this->issue)('nowy@web-systems.pl')->plainToken, 'Nowy', self::PASSWORD);

        $entry = $this->em->getConnection()->fetchAssociative(
            "SELECT actor_user_id, space_slug, target FROM ws.audit_log
             WHERE action = 'space.member_added' ORDER BY created_at DESC LIMIT 1"
        );

        self::assertNotFalse($entry);
        self::assertNull($entry['actor_user_id']);
        self::assertSame($this->sharedSlug, $entry['space_slug']);

        /** @var array<string, mixed> $target */
        $target = json_decode((string) $entry['target'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('default_space', $target['reason'] ?? null);
    }

    public function testAnEmptySlugSwitchesTheWholeThingOff(): void
    {
        $wylaczone = new SharedSpaceForEveryone($this->em, '', 'Baza wiedzy', 'writer');
        $user = new User('nikt@web-systems.pl', 'Nikt');

        self::assertNull($wylaczone->admit($user), 'no slug configured means nothing happens');
    }

    /**
     * A role nobody can read is a configuration mistake, and the safe reading of a
     * mistake is the weakest role. Guessing high would quietly widen access for
     * everybody who joins afterwards.
     */
    public function testAnUnreadableRoleFallsBackToReaderRatherThanWriter(): void
    {
        $krzywe = new SharedSpaceForEveryone($this->em, 'wiedza', 'Baza wiedzy', 'zarzadca');

        self::assertSame(SpaceRole::Reader, $krzywe->role());
    }

    /** @return array<string, string> slug => role, private spaces left out */
    private function teamRolesOf(User $user): array
    {
        $members = $this->em->getRepository(SpaceMember::class)->findBy(['user' => $user]);

        $roles = [];
        foreach ($members as $member) {
            $space = $member->getSpace();
            self::assertInstanceOf(Space::class, $space);

            if (!$space->isPrivate()) {
                $roles[$space->getSlug()] = $member->getRole()->value;
            }
        }

        return $roles;
    }
}
