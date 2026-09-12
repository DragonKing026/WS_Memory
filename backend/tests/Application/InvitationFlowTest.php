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
 */
final class InvitationFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IssueInvitation $issue;
    private AcceptInvitation $accept;
    private SpaceAccessResolver $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->issue = $container->get(IssueInvitation::class);
        $this->accept = $container->get(AcceptInvitation::class);
        $this->access = $container->get(SpaceAccessResolver::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );
    }

    public function testAcceptingInvitationCreatesAccountWithItsOwnPrivateSpace(): void
    {
        $issued = ($this->issue)('nowy@web-systems.pl');

        $user = ($this->accept)($issued->plainToken, 'Nowy Pracownik', 'DlugieHaslo123!x');

        self::assertSame('nowy@web-systems.pl', $user->getEmail());

        $spaces = $this->access->allowedSpaces(Actor::human($user->getId()->toRfc4122()));
        self::assertCount(1, $spaces, 'a fresh account must own exactly its private space');
        self::assertStringStartsWith('priv_', (string) $spaces[0]);
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
