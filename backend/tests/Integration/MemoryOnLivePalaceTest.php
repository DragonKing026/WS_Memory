<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\PalaceWing;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use App\Infrastructure\MemPalace\MemPalaceClient;
use App\Infrastructure\MemPalace\MemPalaceHealthProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The whole chain against a real MemPalace: permissions, wings, embeddings.
 *
 * Everything else is asserted against doubles, which proves the logic and
 * nothing about the protocol. This file is the opposite: it proves that the
 * field names, the argument names and the answer shapes are what we think they
 * are — and those are exactly what a MemPalace upgrade changes without telling
 * anybody.
 *
 * Skipped when the palace does not answer, so the fast CI run stays fast; the
 * nightly run starts the full stack and this file runs there (docs/09-ci.md).
 *
 * Each run gets its own wing. A shared one accumulates drawers that are
 * semantically close to each other run after run, and the palace applies its own
 * result limit before we see anything — eventually a run's own content would be
 * ranked out of its own test, and the failure would look like broken semantics.
 */
#[Group('integracja')]
final class MemoryOnLivePalaceTest extends KernelTestCase
{
    use RequiresLivePalace;

    private const CONTENT = 'Umowa najmu lokalu wymaga aneksu przy zmianie stawki czynszu';
    private const UNRELATED_WORDS = 'zmiana opłaty za wynajem — jakie dokumenty';

    private MemoryService $memory;
    private MemPalaceClient $client;
    private EntityManagerInterface $em;
    private string $wing;
    private Actor $member;
    private Actor $stranger;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $client = $container->get(MemPalaceClient::class);
        self::assertInstanceOf(MemPalaceClient::class, $client);
        $this->client = $client;

        $zdrowie = $container->get(MemPalaceHealthProbe::class);
        self::assertInstanceOf(MemPalaceHealthProbe::class, $zdrowie);
        self::skipUnlessPalaceAnswers($zdrowie);

        $memory = $container->get(MemoryService::class);
        self::assertInstanceOf(MemoryService::class, $memory);
        $this->memory = $memory;

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.memory_entries, ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->wing = 'test-integracja-' . bin2hex(random_bytes(4));

        $member = new User('czlonek@web-systems.pl', 'Członek');
        $member->setPasswordHash('nieistotny');
        $outsider = new User('obcy@web-systems.pl', 'Obcy');
        $outsider->setPasswordHash('nieistotny');

        $space = new Space('integracja', 'Integracja', $this->wing);
        $this->em->persist($member);
        $this->em->persist($outsider);
        $this->em->persist($space);
        $this->em->persist(new SpaceMember($space, $member, SpaceRole::Writer));
        $this->em->flush();

        $this->member = Actor::human($member->getId()->toRfc4122());
        $this->stranger = Actor::human($outsider->getId()->toRfc4122());
    }

    public function testPolishContentIsFoundByWordsItDoesNotContain(): void
    {
        // The assumption the entire project rests on (D-003), asserted through
        // our own permission layer rather than straight against the palace.
        $stored = $this->memory->remember($this->member, self::CONTENT, new SpaceId('integracja'));

        $found = $this->memory->search($this->member, new MemoryQuery(self::UNRELATED_WORDS, limit: 20));

        self::assertContains(
            $stored->drawer->value,
            array_map(static fn (MemoryFragment $f): string => $f->id->value, $found),
            'a Polish query sharing no words with the content must still find it',
        );
    }

    public function testWrittenContentComesBackWholeThroughGet(): void
    {
        $stored = $this->memory->remember($this->member, self::CONTENT, new SpaceId('integracja'));

        $fragment = $this->memory->get($this->member, $stored->drawer);

        self::assertNotNull($fragment);
        self::assertSame(self::CONTENT, $fragment->content);
        self::assertSame('integracja', $fragment->space?->value);
        self::assertSame('technical', $fragment->room, 'a note is filed into the room its kind maps to');
    }

    public function testStrangerFindsNothingAndCanFetchNothing(): void
    {
        $stored = $this->memory->remember($this->member, self::CONTENT, new SpaceId('integracja'));

        self::assertSame([], $this->memory->search($this->stranger, new MemoryQuery(self::UNRELATED_WORDS)));
        self::assertNull($this->memory->get($this->stranger, $stored->drawer));
    }

    public function testDrawerFiledStraightIntoOurWingIsNotReturned(): void
    {
        // The second filtering layer, end to end. Content that reached the
        // palace without passing through us — a restored backup, a mistake, a
        // future feature — has no registry row, so it cannot be handed out.
        $payload = $this->client->call('mempalace_add_drawer', [
            'wing' => $this->wing,
            'room' => 'technical',
            'content' => self::CONTENT . ' (wstawione poza rejestrem)',
            'added_by' => 'test-integracja',
        ]);

        self::assertIsString($payload['drawer_id'] ?? null, 'add_drawer must answer with an identifier we can book');

        $found = $this->memory->search($this->member, new MemoryQuery(self::UNRELATED_WORDS, limit: 20));

        self::assertNotContains(
            (string) $payload['drawer_id'],
            array_map(static fn (MemoryFragment $f): string => $f->id->value, $found),
        );
    }

    public function testSearchIsLimitedToTheRoomOfTheRequestedKind(): void
    {
        $note = $this->memory->remember($this->member, self::CONTENT, new SpaceId('integracja'));
        $document = $this->memory->remember(
            $this->member,
            'Dokumentacja: aneks do umowy najmu podpisuje zarząd',
            new SpaceId('integracja'),
            MemoryKind::Document,
        );

        $documents = $this->memory->search(
            $this->member,
            new MemoryQuery('aneks do umowy', limit: 20, kind: MemoryKind::Document),
        );
        $ids = array_map(static fn (MemoryFragment $f): string => $f->id->value, $documents);

        self::assertContains($document->drawer->value, $ids);
        self::assertNotContains($note->drawer->value, $ids, 'a kind filter must reach the palace as a room filter');
    }

    public function testFactRoundTripsThroughTheKnowledgeGraph(): void
    {
        // Also the only check on the real shape of a fact: the graph was empty
        // everywhere we could look while writing the adapter.
        $this->memory->kgAdd(
            $this->member,
            new KnowledgeFact('WS_Memory', 'uzywa', 'MemPalace'),
            new SpaceId('integracja'),
        );

        $facts = $this->memory->kgQuery($this->member, 'WS_Memory');

        self::assertNotSame([], $facts, 'a fact just written must be readable back');
        self::assertSame('MemPalace', $facts[0]->object, 'the wing prefix must be stripped before it leaves us');
        self::assertSame([], $this->memory->kgQuery($this->stranger, 'WS_Memory'));
    }

    public function testDiaryEntryIsFiledIntoTheSpaceWingAndNotTheAgentOwn(): void
    {
        // A real token identifier, because ws.memory_entries stores it as a UUID:
        // the registry is where an invented one stops being merely untidy.
        $agent = Actor::agent($this->member->userId, Uuid::v7()->toRfc4122(), [new SpaceId('integracja')]);

        $stored = $this->memory->diaryWrite($agent, 'SESSION:2026-09-12|TODO-003|integracja', new SpaceId('integracja'));

        $fragment = $this->memory->get($agent, $stored->drawer);
        self::assertNotNull($fragment, 'a diary entry filed outside our wings would be unreadable for ever');
        self::assertTrue($fragment->wing->equals(new PalaceWing($this->wing)));
        self::assertSame('diary', $fragment->room);
    }

    public function testDrawerTheRegistryDoesNotKnowIsNeverEvenFetched(): void
    {
        // The registry row is the only way in. An identifier it does not hold
        // never reaches the palace, so the answer is a plain null — not an
        // error, and not a hint that something exists elsewhere.
        self::assertNull($this->memory->get($this->member, new DrawerId('drawer_ktorego_nie_ma_w_palacu')));
    }
}
