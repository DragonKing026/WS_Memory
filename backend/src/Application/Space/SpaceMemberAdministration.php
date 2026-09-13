<?php

declare(strict_types=1);

namespace App\Application\Space;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Space\MembershipRefused;
use App\Domain\Space\SpaceDirectory;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceMemberView;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Changing and withdrawing somebody's role in a space, from the administration screen.
 *
 * The complement to SpaceAdministrationController, which creates spaces and grants roles.
 * Granting had a home already; taking away did not, and the two operations that take away
 * are the ones with a rule attached — so they live here, in one place, rather than being
 * written into a controller where the next surface (a console command for a support case,
 * the MCP gateway if it ever needs them) would have to repeat the rule or quietly skip it.
 *
 * Two refusals are the substance of this class, and both are explained at length on
 * MembershipRefused: a space must not be left without an administrator, and a private
 * space's membership is not something anybody edits. Everything else here is lookup.
 *
 * Every change is audited with the identity of whoever made it. This is the surface where
 * a global administrator reaches into a space they were never a member of, which is
 * allowed and must never be silent (D-016).
 */
final readonly class SpaceMemberAdministration
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpaceDirectory $directory,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return list<SpaceMemberView>
     *
     * @throws MembershipRefused when there is no such space
     */
    public function members(string $slug): array
    {
        // The space is looked up even though the listing query would return an empty
        // result for an unknown slug: "no such space" and "a space nobody belongs to"
        // are different answers, and a screen cannot tell them apart from an empty list.
        //
        // Reading is allowed for a private space, where changing is not. Its membership is
        // one person the administrator can already see in the space list — the slug carries
        // the owner's id — so refusing to show it would hide nothing and only leave the
        // panel unable to open a row it is showing. What must never happen quietly is the
        // change, and that is guarded on the two methods that make one.
        $this->require($slug);

        return $this->directory->membersOf(new SpaceId($slug));
    }

    /**
     * @throws MembershipRefused when the space or the member is unknown, the role is not
     *                           a role, the space is private, or this would leave the
     *                           space without an administrator
     */
    public function changeRole(User $administrator, string $slug, string $userId, string $role): SpaceMemberView
    {
        $space = $this->requireShared($slug);
        $wanted = SpaceRole::tryFrom($role);
        if (null === $wanted) {
            throw MembershipRefused::malformedRole($role);
        }

        $membership = $this->membershipOf($space, $userId);
        $previous = $membership->getRole();

        if ($previous === $wanted) {
            // Nothing to change, so nothing is written — including no audit entry. An
            // entry saying a role became what it already was is noise in the one log
            // that has to stay readable, and a button pressed twice writes it.
            return self::viewOf($membership);
        }

        if (SpaceRole::Admin === $previous && $this->isLastAdministrator($space)) {
            throw MembershipRefused::lastAdministrator($slug);
        }

        $membership->changeRole($wanted);

        $this->audit->record(
            action: 'space.member_role_changed',
            actor: self::actorOf($administrator),
            spaceSlug: $slug,
            target: [
                'member' => $membership->getUser()->getEmail(),
                'role' => $wanted->value,
                // The role it was before is in the entry on purpose: an audit log of role
                // changes that records only the new value cannot answer "was this a
                // promotion", which is the question somebody reviewing it is asking.
                'previousRole' => $previous->value,
            ],
        );

        $this->entityManager->flush();

        return self::viewOf($membership);
    }

    /**
     * @throws MembershipRefused when the space or the member is unknown, the space is
     *                           private, or this would leave the space without an
     *                           administrator
     */
    public function remove(User $administrator, string $slug, string $userId): void
    {
        $space = $this->requireShared($slug);
        $membership = $this->membershipOf($space, $userId);

        if (SpaceRole::Admin === $membership->getRole() && $this->isLastAdministrator($space)) {
            throw MembershipRefused::lastAdministrator($slug);
        }

        // Read before the entity goes, because it is what the log entry names. An id
        // would leave the trail readable only for as long as the account exists.
        $email = $membership->getUser()->getEmail();
        $role = $membership->getRole()->value;

        $this->entityManager->remove($membership);

        $this->audit->record(
            action: 'space.member_removed',
            actor: self::actorOf($administrator),
            spaceSlug: $slug,
            target: ['member' => $email, 'role' => $role],
        );

        $this->entityManager->flush();
    }

    /**
     * @throws MembershipRefused when there is no such space
     */
    private function require(string $slug): Space
    {
        $space = $this->entityManager->getRepository(Space::class)->findOneBy(['slug' => $slug]);
        if (null === $space) {
            throw MembershipRefused::unknownSpace($slug);
        }

        return $space;
    }

    /**
     * The same lookup, for the operations that CHANGE a membership.
     *
     * The private-space rule sits here rather than in each method, so that a method added
     * later gets it by asking for a space the way the existing ones do — and a method that
     * only reads has to say so explicitly.
     *
     * @throws MembershipRefused when there is no such space, or it is private
     */
    private function requireShared(string $slug): Space
    {
        $space = $this->require($slug);

        if ($space->isPrivate()) {
            throw MembershipRefused::privateSpace($slug);
        }

        return $space;
    }

    /**
     * @throws MembershipRefused
     */
    private function membershipOf(Space $space, string $userId): SpaceMember
    {
        // An identifier that is not a UUID cannot name a member, and asking Doctrine
        // about it would be a database error rather than an answer. Same 404 either way:
        // for the caller, "malformed id" and "nobody by that id" are one situation.
        if (!Uuid::isValid($userId)) {
            throw MembershipRefused::unknownMember($space->getSlug());
        }

        $user = $this->entityManager->getRepository(User::class)->find(Uuid::fromString($userId));
        $membership = $user instanceof User
            ? $this->entityManager->getRepository(SpaceMember::class)->findOneBy(['space' => $space, 'user' => $user])
            : null;

        if (!$membership instanceof SpaceMember) {
            throw MembershipRefused::unknownMember($space->getSlug());
        }

        return $membership;
    }

    /**
     * Whether this space has exactly one administrator left.
     *
     * Counted in the database rather than over a loaded collection: the answer decides
     * whether a write is allowed, and a stale count would decide it wrongly.
     */
    private function isLastAdministrator(Space $space): bool
    {
        return 1 === $this->entityManager->getRepository(SpaceMember::class)->count([
            'space' => $space,
            'role' => SpaceRole::Admin,
        ]);
    }

    private static function viewOf(SpaceMember $membership): SpaceMemberView
    {
        return new SpaceMemberView(
            userId: $membership->getUser()->getId()->toRfc4122(),
            displayName: $membership->getUser()->getDisplayName(),
            email: $membership->getUser()->getEmail(),
            role: $membership->getRole(),
            addedAt: $membership->getAddedAt(),
            addedBy: $membership->getAddedBy()?->getDisplayName(),
        );
    }

    private static function actorOf(User $administrator): Actor
    {
        return Actor::human($administrator->getId()->toRfc4122(), $administrator->isGlobalAdmin());
    }
}
