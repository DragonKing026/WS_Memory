<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Publishing\Mirror;
use App\Domain\Publishing\MirrorDirectory;
use App\Domain\Publishing\PublishBatch;
use App\Domain\Publishing\PublishBatchRepository;
use App\Domain\Publishing\PublishBatchStatus;
use App\Domain\Publishing\PublishMode;
use App\Domain\Publishing\SkipReason;
use App\Domain\Publishing\SkippedDrawer;
use App\Domain\Space\SpaceId;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The publication bridge against a real PostgreSQL, because its promises are the schema's.
 *
 * Three of them cannot be asserted with a double, and each is the mechanism behind
 * one of TODO-012's requirements rather than a nicety:
 *
 *  - the source pair is unique WHERE both halves are set. That partial index is what
 *    makes an outbox safe to retry forever (D-015) — and "partial" is the half that
 *    needs proving, because every row written on the server carries no replica at all
 *    and there will be millions of them;
 *  - `(space_id, content_hash)` is indexed and NOT unique, because skipping duplicates
 *    is a publishing policy and two people writing the same sentence in the wiki must
 *    not meet a failed write;
 *  - a batch is kept after being undone, with its own timestamp, and the check
 *    constraint makes the status and the timestamp unable to disagree.
 */
final class DoctrinePublishBridgeTest extends KernelTestCase
{
    private const REPLICA = 'laptop-artura';

    private MemoryRegistry $registry;
    private PublishBatchRepository $batches;
    private MirrorDirectory $mirrors;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $author;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Fetched by their ports, which also makes this the one place that checks
        // config/services.yaml points them at the Doctrine adapters at all.
        $this->registry = $container->get(MemoryRegistry::class);
        $this->batches = $container->get(PublishBatchRepository::class);
        $this->mirrors = $container->get(MirrorDirectory::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.memory_entries, ws.publish_batches, ws.mirrors, ws.publish_settings, '
            . 'ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->author = new User('autor@web-systems.pl', 'Autor');
        $this->author->setPasswordHash('nieistotny');
        $this->em->persist($this->author);
        $this->em->persist(new Space('alfa', 'Alfa', 'wing_alfa'));
        $this->em->persist(new Space('beta', 'Beta', 'wing_beta'));
        $this->em->flush();
    }

    // -------------------------------------------------- the source pair is unique

    public function testTheSameLocalDrawerCannotBeBookedTwice(): void
    {
        $this->registry->register($this->fromReplica('drawer_alfa_1', 'alfa', 'drawer_lokalny_1'));

        // The index is what makes republishing safe even if the code forgets to look
        // first. Reached here on purpose, with a different server-side drawer id, so
        // that only the source pair can be what refuses it.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->registry->register($this->fromReplica('drawer_alfa_2', 'alfa', 'drawer_lokalny_1'));
    }

    /**
     * The index is partial, and that is not an optimisation.
     *
     * Everything written through the wiki or `ws_remember` carries no replica, and
     * there will be far more of those than of published rows. A full unique index
     * would let exactly one of them exist.
     */
    public function testRowsWithNoReplicaAreNotConstrainedAgainstEachOther(): void
    {
        $this->registry->register($this->serverSide('drawer_alfa_1', 'alfa'));
        $this->registry->register($this->serverSide('drawer_alfa_2', 'alfa'));

        self::assertCount(2, $this->registry->spacesFor([
            new DrawerId('drawer_alfa_1'),
            new DrawerId('drawer_alfa_2'),
        ]));
    }

    public function testTheBindingSaysWhichDrawerAndWhichSpaceALocalDrawerAlreadyHas(): void
    {
        $this->registry->register($this->fromReplica('drawer_alfa_1', 'alfa', 'drawer_lokalny_1'));

        $binding = $this->registry->bindingForSource(self::REPLICA, 'drawer_lokalny_1');

        self::assertNotNull($binding);
        self::assertSame('drawer_alfa_1', $binding->drawer->value);
        self::assertSame('alfa', $binding->space->value);
        self::assertNull(
            $this->registry->bindingForSource('inny-laptop', 'drawer_lokalny_1'),
            'ta sama szuflada z innej repliki to inna treść',
        );
    }

    // --------------------------------------------------------- duplicate screening

    public function testContentIsFoundByItsHashWithinASpaceAndNotAcrossSpaces(): void
    {
        $this->registry->register($this->fromReplica('drawer_alfa_1', 'alfa', 'drawer_lokalny_1', 'wspólna treść'));
        $hash = MemoryWrite::hashOf('wspólna treść');

        self::assertSame(
            'drawer_alfa_1',
            $this->registry->drawerWithContent(new SpaceId('alfa'), $hash)?->value,
        );
        self::assertNull(
            $this->registry->drawerWithContent(new SpaceId('beta'), $hash),
            'odsiew działa w obrębie przestrzeni docelowej (D-014), nie globalnie',
        );
    }

    // ------------------------------------------------------ refreshing and moving

    /**
     * Confirming a mapping and resending moves content from the private space to the
     * team one, in one row rather than two.
     */
    public function testRefreshingMovesTheRowToTheNewSpaceWithoutAddingASecond(): void
    {
        $this->registry->register($this->fromReplica('drawer_alfa_1', 'alfa', 'drawer_lokalny_1', 'pierwsza wersja'));

        $this->registry->refresh($this->fromReplica('drawer_alfa_1', 'beta', 'drawer_lokalny_1', 'druga wersja'));

        self::assertSame('beta', $this->registry->spaceFor(new DrawerId('drawer_alfa_1'))?->value);
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM ws.memory_entries'),
            'odświeżenie aktualizuje wiersz, nie dopisuje',
        );
        self::assertSame(
            MemoryWrite::hashOf('druga wersja'),
            (string) $this->connection->fetchOne('SELECT content_hash FROM ws.memory_entries'),
        );
    }

    public function testRefreshingARowThatIsNotThereFailsLoudly(): void
    {
        $this->expectException(\DomainException::class);
        $this->registry->refresh($this->fromReplica('drawer_widmo', 'alfa', 'drawer_lokalny_1'));
    }

    /**
     * A laptop back from a week offline must not date a week of drawers "today".
     *
     * The browse screen is ordered by this column, so a guessed timestamp would claim
     * a week of work happened in one minute — and nobody would ever know why.
     */
    public function testTheLocalFilingTimeIsKeptRatherThanReplacedWithNow(): void
    {
        $filedAt = new \DateTimeImmutable('2026-09-01 08:30:00');

        $this->registry->register(MemoryWrite::fromReplica(
            new DrawerId('drawer_alfa_1'),
            new SpaceId('alfa'),
            $this->actor(),
            self::REPLICA,
            'drawer_lokalny_1',
            $this->givenBatch('alfa'),
            'treść',
            filedAt: $filedAt,
        ));

        self::assertSame(
            '2026-09-01 08:30:00',
            (string) $this->connection->fetchOne('SELECT created_at FROM ws.memory_entries'),
        );
    }

    // ------------------------------------------------------------- batch and undo

    public function testTheDrawersOfABatchAreFoundAndForgottenTogether(): void
    {
        $batchId = $this->givenBatch('alfa');
        $other = $this->givenBatch('alfa');

        $this->registry->register($this->fromReplica('drawer_alfa_1', 'alfa', 'lokalny_1', 'jedna', $batchId));
        $this->registry->register($this->fromReplica('drawer_alfa_2', 'alfa', 'lokalny_2', 'druga', $batchId));
        $this->registry->register($this->fromReplica('drawer_alfa_3', 'alfa', 'lokalny_3', 'trzecia', $other));

        $drawers = $this->registry->drawersInBatch($batchId);
        self::assertCount(2, $drawers);

        self::assertSame(2, $this->registry->forget($drawers));
        self::assertSame(
            'drawer_alfa_3',
            (string) $this->connection->fetchOne('SELECT drawer_id FROM ws.memory_entries'),
            'wycofanie jednej partii nie rusza drugiej',
        );
    }

    /**
     * A drawer may be booked in the same transaction as the batch that accounts for
     * it, in either order.
     *
     * That is what the deferred foreign key buys, and it is why the batch can report
     * the counts that actually happened instead of being inserted first with numbers
     * it cannot yet know.
     */
    public function testADrawerMayBeBookedBeforeTheBatchItBelongsTo(): void
    {
        $batchId = Uuid::v7()->toRfc4122();

        $this->registry->transactional(function () use ($batchId): void {
            $this->registry->register($this->fromReplica('drawer_alfa_1', 'alfa', 'lokalny_1', 'treść', $batchId));
            $this->batches->record($this->batch($batchId, 'alfa', drawerCount: 1));
        });

        self::assertSame('alfa', $this->registry->spaceFor(new DrawerId('drawer_alfa_1'))?->value);
        self::assertSame(PublishBatchStatus::Applied, $this->batches->find($batchId)?->status);
    }

    public function testABatchSurvivesBeingUndoneAndRemembersWhen(): void
    {
        $batchId = $this->givenBatch('alfa');
        $at = new \DateTimeImmutable('2026-09-13 12:00:00');

        $this->batches->markReverted($batchId, $at);

        $batch = $this->batches->find($batchId);
        self::assertNotNull($batch);
        self::assertSame(PublishBatchStatus::Reverted, $batch->status);
        self::assertSame('2026-09-13 12:00:00', $batch->revertedAt?->format('Y-m-d H:i:s'));
        self::assertFalse($batch->isRevertable());
    }

    /**
     * Two clients pressing undo at once: the database picks the winner.
     *
     * The guard is in the WHERE clause rather than a read followed by a write, so
     * both callers cannot come away believing they did it.
     */
    public function testUndoingTheSameBatchTwiceIsRefusedByTheDatabase(): void
    {
        $batchId = $this->givenBatch('alfa');
        $this->batches->markReverted($batchId, new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $this->batches->markReverted($batchId, new \DateTimeImmutable());
    }

    public function testTheSkipReportSurvivesTheRoundTrip(): void
    {
        $batchId = Uuid::v7()->toRfc4122();
        $this->batches->record($this->batch($batchId, 'alfa', skipped: [
            new SkippedDrawer('drawer_z_sekretem', SkipReason::Secret, 'klucz prywatny (wiersz 2)'),
            SkippedDrawer::duplicate('drawer_powtorzony'),
        ]));

        $batch = $this->batches->find($batchId);

        self::assertNotNull($batch);
        self::assertSame(2, $batch->skippedCount());
        self::assertSame('drawer_z_sekretem', $batch->skipped[0]->sourceDrawerId);
        self::assertStringContainsString('klucz prywatny', $batch->skipped[0]->describe());
        self::assertSame(SkipReason::Duplicate, $batch->skipped[1]->reason);
    }

    // ------------------------------------------------------------------- mirrors

    public function testAMappingIsFoundByOwnerReplicaAndWing(): void
    {
        $this->givenMirror(['diary'], isConfirmed: true);

        $mirror = $this->mirrors->mirrorFor($this->author->getId()->toRfc4122(), self::REPLICA, 'ws-memory');

        self::assertInstanceOf(Mirror::class, $mirror);
        self::assertSame('alfa', $mirror->space->value);
        self::assertSame(['diary'], $mirror->excludedRooms);
        self::assertTrue($mirror->isConfirmed);
        self::assertTrue($mirror->routes('technical'));
        self::assertFalse($mirror->routes('diary'));
    }

    /**
     * One person's confirmation is not another's.
     *
     * Two people mining the same repository have wings of the same name, and a lookup
     * keyed on the wing alone would make the first confirmation apply to both — which
     * is exactly the leak the rule exists to prevent.
     */
    public function testAMappingBelongsToOneOwnerOnly(): void
    {
        $this->givenMirror(isConfirmed: true);

        self::assertNull($this->mirrors->mirrorFor(Uuid::v7()->toRfc4122(), self::REPLICA, 'ws-memory'));
        self::assertNull($this->mirrors->mirrorFor($this->author->getId()->toRfc4122(), 'inny-laptop', 'ws-memory'));
    }

    /**
     * The default is FALSE, and the default is what decides visibility (D-014).
     *
     * A row inserted without saying anything about confirmation routes nothing to a
     * team space. Were the column to default to true, proposing a mapping would
     * publish to a team.
     */
    public function testANewMappingIsUnconfirmedUntilSomebodySaysOtherwise(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.mirrors (id, user_id, source_replica, source_wing, space_id, created_at)
                SELECT :id, :user, :replica, 'ws-memory', s.id, :now
                FROM ws.spaces s WHERE s.slug = 'alfa'
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $this->author->getId()->toRfc4122(),
                'replica' => self::REPLICA,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $mirror = $this->mirrors->mirrorFor($this->author->getId()->toRfc4122(), self::REPLICA, 'ws-memory');

        self::assertInstanceOf(Mirror::class, $mirror);
        self::assertFalse($mirror->isConfirmed, 'potwierdzenie jest czynnością człowieka, nie domyślną wartością');
        self::assertFalse($mirror->routes('technical'));
    }

    // ---------------------------------------------------------------- helpers

    private function actor(): Actor
    {
        return Actor::human($this->author->getId()->toRfc4122());
    }

    private function fromReplica(
        string $drawerId,
        string $spaceSlug,
        string $sourceDrawerId,
        string $content = 'treść szuflady',
        ?string $batchId = null,
    ): MemoryWrite {
        return MemoryWrite::fromReplica(
            new DrawerId($drawerId),
            new SpaceId($spaceSlug),
            $this->actor(),
            self::REPLICA,
            $sourceDrawerId,
            $batchId ?? $this->givenBatch($spaceSlug),
            $content,
        );
    }

    private function serverSide(string $drawerId, string $spaceSlug): MemoryWrite
    {
        return MemoryWrite::ofContent(
            new DrawerId($drawerId),
            new SpaceId($spaceSlug),
            MemoryKind::Note,
            $this->actor(),
            'notatka zapisana na serwerze: ' . $drawerId,
        );
    }

    /** @param list<SkippedDrawer> $skipped */
    private function batch(
        string $id,
        string $spaceSlug,
        int $drawerCount = 0,
        array $skipped = [],
    ): PublishBatch {
        return new PublishBatch(
            id: $id,
            userId: $this->author->getId()->toRfc4122(),
            agentTokenId: null,
            mirrorId: null,
            space: new SpaceId($spaceSlug),
            sourceReplica: self::REPLICA,
            mode: PublishMode::Selective,
            status: PublishBatchStatus::Applied,
            drawerCount: $drawerCount,
            skipped: $skipped,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function givenBatch(string $spaceSlug): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->batches->record($this->batch($id, $spaceSlug));

        return $id;
    }

    /** @param list<string> $excludedRooms */
    private function givenMirror(array $excludedRooms = [], bool $isConfirmed = false): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.mirrors (
                    id, user_id, source_replica, source_wing, space_id,
                    excluded_rooms, is_active, is_confirmed, created_at
                )
                SELECT :id, :user, :replica, 'ws-memory', s.id,
                       CAST(:rooms AS JSONB), TRUE, :confirmed, :now
                FROM ws.spaces s WHERE s.slug = 'alfa'
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $this->author->getId()->toRfc4122(),
                'replica' => self::REPLICA,
                'rooms' => json_encode($excludedRooms, \JSON_THROW_ON_ERROR),
                'confirmed' => $isConfirmed ? 'true' : 'false',
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }
}
