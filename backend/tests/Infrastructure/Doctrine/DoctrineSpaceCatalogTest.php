<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceCatalog;
use App\Domain\Space\SpaceId;
use App\Entity\Space;
use App\Infrastructure\Doctrine\DoctrineSpaceCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where a write goes when nobody said where.
 *
 * The private space is the default landing place for every write that names no
 * space (inviolable rule 6), so "does this user have one?" has to be answered
 * from the database rather than assumed from a naming convention. The last test
 * is the reason: a space that merely looks private, because somebody named it
 * with the reserved prefix, must not become anybody's default.
 */
final class DoctrineSpaceCatalogTest extends KernelTestCase
{
    private SpaceCatalog $catalog;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $catalog = $container->get(SpaceCatalog::class);
        self::assertInstanceOf(DoctrineSpaceCatalog::class, $catalog);
        $this->catalog = $catalog;

        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.memory_entries, ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );
    }

    public function testSpaceWingMayDifferFromItsSlug(): void
    {
        $this->em->persist(new Space('alfa', 'Alfa', 'skrzydlo_alfa'));
        $this->em->flush();

        self::assertSame('skrzydlo_alfa', $this->catalog->wingFor(new SpaceId('alfa'))?->value);
    }

    public function testSpaceThatDoesNotExistHasNoWing(): void
    {
        self::assertNull($this->catalog->wingFor(new SpaceId('nie-ma-takiej')));
    }

    public function testAcceptedInvitationGivesTheUserAPrivateSpace(): void
    {
        $container = static::getContainer();
        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        $user = ($accept)(($issue)('nowy@web-systems.pl')->plainToken, 'Nowy', 'DlugieHaslo123!x');

        $private = $this->catalog->privateSpaceOf($user->getId()->toRfc4122());

        self::assertNotNull($private, 'every account must have somewhere for an unaddressed write to land');
        self::assertTrue($private->isPrivate());
        self::assertNotNull($this->catalog->wingFor($private));
    }

    public function testSpaceNamedLikeAPrivateOneButNotMarkedAsSuchIsNotReturned(): void
    {
        // Both conditions are checked: the convention AND the flag. Either alone
        // would find the row; together a hand-made impostor cannot become the
        // place somebody else's unaddressed writes land in.
        $this->em->persist(new Space('priv_podrobka', 'Podróbka'));
        $this->em->flush();

        self::assertNull($this->catalog->privateSpaceOf('podrobka'));
    }
}
