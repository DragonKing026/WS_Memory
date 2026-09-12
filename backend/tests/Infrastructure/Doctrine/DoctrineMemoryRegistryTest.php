<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Space\SpaceId;
use App\Infrastructure\Doctrine\DoctrineMemoryRegistry;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The registry against a real PostgreSQL, because its guarantees are the schema's.
 *
 * A unique index and a foreign key cannot be asserted with a double: an
 * in-memory registry will happily accept two rows for one drawer and a row
 * pointing at a space that was deleted. Those are exactly the two mistakes that
 * would make "which space is this in?" ambiguous, and an ambiguous answer on the
 * permission path is not something to find out in production.
 */
final class DoctrineMemoryRegistryTest extends KernelTestCase
{
    private MemoryRegistry $registry;
    private EntityManagerInterface $em;
    private User $author;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Fetched by its port and asserted to be the Doctrine adapter: this is
        // also the only place that checks the wiring in config/services.yaml
        // actually points the port at it.
        $registry = $container->get(MemoryRegistry::class);
        self::assertInstanceOf(DoctrineMemoryRegistry::class, $registry);
        $this->registry = $registry;

        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.memory_entries, ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->author = new User('autor@web-systems.pl', 'Autor');
        $this->author->setPasswordHash('nieistotny');
        $this->em->persist($this->author);
        $this->em->persist(new Space('alfa', 'Alfa', 'wing_alfa'));
        $this->em->flush();
    }

    public function testRegisteredDrawerIsFoundInItsSpace(): void
    {
        $this->registry->register($this->writeOf('drawer_alfa_1', 'alfa'));

        self::assertSame('alfa', $this->registry->spaceFor(new DrawerId('drawer_alfa_1'))?->value);
    }

    public function testUnknownDrawerHasNoSpace(): void
    {
        self::assertNull($this->registry->spaceFor(new DrawerId('drawer_nieznany')));
    }

    public function testSpacesForAnswersOnlyForIdentifiersItKnows(): void
    {
        $this->registry->register($this->writeOf('drawer_alfa_1', 'alfa'));

        $spaces = $this->registry->spacesFor([
            new DrawerId('drawer_alfa_1'),
            new DrawerId('drawer_nieznany'),
        ]);

        self::assertSame(['drawer_alfa_1'], array_keys($spaces));
    }

    public function testRegisteringForASpaceThatDoesNotExistFails(): void
    {
        $this->expectException(\DomainException::class);
        $this->registry->register($this->writeOf('drawer_widmo', 'nie-ma-takiej'));
    }

    public function testOneDrawerCannotBelongToTwoSpaces(): void
    {
        $this->em->persist(new Space('beta', 'Beta', 'wing_beta'));
        $this->em->flush();

        $this->registry->register($this->writeOf('drawer_alfa_1', 'alfa'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->registry->register($this->writeOf('drawer_alfa_1', 'beta'));
    }

    public function testTheSameContentMayBeRecordedTwiceInOneSpace(): void
    {
        // Skipping duplicates is a publishing policy (D-010), not a rule of the
        // table. Two people may record the same sentence, and the registry must
        // not be the thing that refuses it.
        $this->registry->register($this->writeOf('drawer_alfa_1', 'alfa', 'ta sama treść'));
        $this->registry->register($this->writeOf('drawer_alfa_2', 'alfa', 'ta sama treść'));

        self::assertCount(2, $this->registry->spacesFor([
            new DrawerId('drawer_alfa_1'),
            new DrawerId('drawer_alfa_2'),
        ]));
    }

    public function testAFailureInsideTheTransactionLeavesNothingBehind(): void
    {
        try {
            $this->registry->transactional(function (): void {
                $this->registry->register($this->writeOf('drawer_alfa_1', 'alfa'));

                throw new \RuntimeException('pałac nie odpowiedział');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('pałac nie odpowiedział', $e->getMessage(), 'the failure must propagate unchanged');
        }

        // Asserted outside the catch on purpose: were the exception swallowed
        // and the transaction committed, this row would be here.
        self::assertNull(
            $this->registry->spaceFor(new DrawerId('drawer_alfa_1')),
            'a write whose transaction failed must leave no row',
        );
    }

    public function testTagsSurviveTheRoundTrip(): void
    {
        $this->registry->register(new MemoryWrite(
            new DrawerId('drawer_alfa_1'),
            new SpaceId('alfa'),
            MemoryKind::Note,
            Actor::human($this->author->getId()->toRfc4122()),
            'Tytuł',
            hash('sha256', 'treść'),
            ['umowy', 'najem'],
        ));

        $tags = $this->em->getConnection()->fetchOne(
            'SELECT tags FROM ws.memory_entries WHERE drawer_id = :id',
            ['id' => 'drawer_alfa_1'],
        );

        self::assertSame(['umowy', 'najem'], json_decode((string) $tags, true));
    }

    private function writeOf(string $drawerId, string $spaceSlug, string $content = 'treść notatki'): MemoryWrite
    {
        return MemoryWrite::ofContent(
            new DrawerId($drawerId),
            new SpaceId($spaceSlug),
            MemoryKind::Note,
            Actor::human($this->author->getId()->toRfc4122()),
            $content,
        );
    }
}
