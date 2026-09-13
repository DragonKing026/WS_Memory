<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Invitation is the only way an account comes into existence, and accepting one
 * is the only moment a private space is created. Both halves are tested
 * together because a half-completed acceptance — an account with nowhere to
 * write — would break inviolable rule 6 on the very first agent write.
 *
 * Accepting an invitation now also puts the account in the shared space everybody
 * belongs to, in the same transaction and for a related reason: an account that
 * exists but reaches no team knowledge is an account somebody has to finish
 * creating by hand.
 */
final class InvitationFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IssueInvitation $issue;
    private AcceptInvitation $accept;
    private SpaceAccessResolver $access;
    private string $sharedSlug;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Configuration, not a constant: the test environment uses a different slug
        // than production on purpose, and this test is about the rule, not the value.
        $slug = $container->getParameter('app.default_space.slug');
        self::assertIsString($slug);

        $this->sharedSlug = $slug;

        $this->em = $container->get(EntityManagerInterface::class);
        $this->issue = $container->get(IssueInvitation::class);
        $this->accept = $container->get(AcceptInvitation::class);
        $this->access = $container->get(SpaceAccessResolver::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );
    }

    /**
     * A fresh account reaches exactly two spaces, and which is which is the point.
     *
     * It used to reach one, and "exactly its private space" was the whole rule. That
     * stopped being true: the account is now also put in the shared space everybody
     * belongs to. Both are named instead of counted, because they answer different
     * questions — the private one is where a write that names no space lands
     * (inviolable rule 6), the shared one is what makes the account useful on its
     * first day without an administrator adding it to anything.
     */
    public function testAcceptingInvitationCreatesPrivateSpaceAndJoinsTheSharedOne(): void
    {
        $issued = ($this->issue)('nowy@web-systems.pl');

        $user = ($this->accept)($issued->plainToken, 'Nowy Pracownik', 'DlugieHaslo123!x');

        self::assertSame('nowy@web-systems.pl', $user->getEmail());

        $slugs = array_map(
            static fn (SpaceId $space): string => (string) $space,
            $this->access->allowedSpaces(Actor::human($user->getId()->toRfc4122())),
        );
        sort($slugs);

        $expected = ['priv_' . $user->getId()->toRfc4122(), $this->sharedSlug];
        sort($expected);

        self::assertSame($expected, $slugs, 'the private space to write into and the shared one to read');
    }

    public function testPrivateSpaceIsInvisibleToEveryoneElse(): void
    {
        $owner = ($this->accept)(
            ($this->issue)('wlasciciel@web-systems.pl')->plainToken,
            'Właściciel',
            'DlugieHaslo123!x',
        );
        $stranger = ($this->accept)(
            ($this->issue)('obcy@web-systems.pl')->plainToken,
            'Obcy',
            'DlugieHaslo123!x',
        );

        $ownersSpace = new SpaceId('priv_' . $owner->getId()->toRfc4122());

        self::assertTrue($this->access->canRead(Actor::human($owner->getId()->toRfc4122()), $ownersSpace));
        self::assertFalse(
            $this->access->canRead(Actor::human($stranger->getId()->toRfc4122()), $ownersSpace),
            'a private space must be invisible to anyone but its owner',
        );
    }

    public function testTokenCannotBeUsedTwice(): void
    {
        $issued = ($this->issue)('jednorazowy@web-systems.pl');
        ($this->accept)($issued->plainToken, 'Pierwszy', 'DlugieHaslo123!x');

        $this->expectException(\DomainException::class);
        ($this->accept)($issued->plainToken, 'Drugi', 'DlugieHaslo123!x');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $issued = ($this->issue)('spozniony@web-systems.pl');

        $invitation = $this->em->getRepository(Invitation::class)->find($issued->invitationId);
        self::assertInstanceOf(Invitation::class, $invitation);

        // Reach into the past rather than waiting: the rule under test is
        // "expired tokens are refused", not "time passes".
        $this->em->getConnection()->executeStatement(
            "UPDATE ws.invitations SET expires_at = NOW() - INTERVAL '1 day' WHERE id = :id",
            ['id' => $issued->invitationId],
        );
        $this->em->clear();

        $this->expectException(\DomainException::class);
        ($this->accept)($issued->plainToken, 'Spóźniony', 'DlugieHaslo123!x');
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        ($this->accept)('token-ktorego-nie-ma', 'Nikt', 'DlugieHaslo123!x');
    }

    public function testIssuingStoresOnlyTheHashOfTheToken(): void
    {
        $issued = ($this->issue)('hash@web-systems.pl');

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT token_hash FROM ws.invitations WHERE id = :id',
            ['id' => $issued->invitationId],
        );

        self::assertNotSame($issued->plainToken, $stored, 'the plain token must never be stored');
        self::assertSame(hash('sha256', $issued->plainToken), $stored);
    }

    public function testAcceptanceIsRecordedInTheAuditLog(): void
    {
        $issued = ($this->issue)('audyt@web-systems.pl');
        $user = ($this->accept)($issued->plainToken, 'Audyt', 'DlugieHaslo123!x');

        $actions = $this->em->getConnection()->fetchFirstColumn(
            'SELECT action FROM ws.audit_log WHERE actor_user_id = :id ORDER BY created_at',
            ['id' => $user->getId()->toRfc4122()],
        );

        self::assertContains('invitation.accepted', $actions);
    }
}
