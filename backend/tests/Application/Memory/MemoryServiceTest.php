<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Application\Memory\MemoryService;
use App\Domain\Identity\Actor;
use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\MemoryUnavailable;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceRole;
use PHPUnit\Framework\TestCase;

/**
 * The one door between our permissions and the palace.
 *
 * Every test below is about something that fails silently if it is wrong. A
 * search that forgets its wing filter returns more, not less; a drawer from
 * somebody else's space arrives looking exactly like one's own; a write whose
 * bookkeeping failed leaves content that can never be found again. None of
 * these throw on their own, which is why they are asserted here.
 */
final class MemoryServiceTest extends TestCase
{
    private const OWNER = 'user-1';
    private const STRANGER = 'user-2';

    private InMemoryMemoryStore $palace;
    private RecordingLexicalIndex $lexical;
    private InMemoryMemoryRegistry $registry;
    private RecordingAuditTrail $audit;

    protected function setUp(): void
    {
        $this->palace = new InMemoryMemoryStore();
        $this->lexical = new RecordingLexicalIndex();
        $this->registry = new InMemoryMemoryRegistry();
        $this->audit = new RecordingAuditTrail();
    }

    // ---------------------------------------------------------------- reading

    public function testEverySearchCarriesAWingFilter(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'treść z przestrzeni alfa');
        $this->palace->given('wing_beta', 'drawer_beta_1', 'treść z przestrzeni beta');
        $this->registerDrawer('drawer_alfa_1', 'alfa');
        $this->registerDrawer('drawer_beta_1', 'beta');

        $service = $this->serviceFor([
            self::OWNER => ['alfa' => SpaceRole::Reader, 'beta' => SpaceRole::Reader],
        ]);

        $service->search(Actor::human(self::OWNER), new MemoryQuery('cokolwiek'));

        self::assertCount(2, $this->palace->searches, 'one call per allowed space, never one unfiltered call');
        self::assertSame(
            ['wing_alfa', 'wing_beta'],
            array_column($this->palace->searches, 'wing'),
        );
    }

    public function testSearchWithoutAnyAllowedSpaceNeverReachesThePalace(): void
    {
        // The empty intersection is the case worth guarding: it is where a
        // "filter only if we have something to filter by" shortcut would turn
        // into an unfiltered query over everybody's content.
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $results = $service->search(Actor::human(self::STRANGER), new MemoryQuery('cokolwiek'));

        self::assertSame([], $results);
        self::assertSame([], $this->palace->searches, 'the palace must not be asked at all');
    }

    public function testRequestedSpacesCanOnlyNarrowThePermittedSet(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'treść alfa');
        $this->registerDrawer('drawer_alfa_1', 'alfa');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $service->search(
            Actor::human(self::OWNER),
            new MemoryQuery('cokolwiek'),
            [new SpaceId('alfa'), new SpaceId('kadry')],
        );

        self::assertSame(['wing_alfa'], array_column($this->palace->searches, 'wing'));
    }

    public function testAskingOnlyForAForbiddenSpaceReturnsEmptyWithoutAskingThePalace(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $results = $service->search(
            Actor::human(self::OWNER),
            new MemoryQuery('cokolwiek'),
            [new SpaceId('kadry')],
        );

        self::assertSame([], $results);
        self::assertSame([], $this->palace->searches);
    }

    public function testDrawerThePalaceReturnsFromAnUnregisteredWingIsDropped(): void
    {
        // The second filtering layer. The palace is a separate process with its
        // own bugs and its own history; if it ever answers with content the
        // registry does not place in an allowed space, that content must not
        // reach the caller regardless of which wing we asked for.
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'treść własna');
        $this->palace->given('wing_alfa', 'drawer_obcy_1', 'treść obcej przestrzeni');
        $this->registerDrawer('drawer_alfa_1', 'alfa');
        $this->registerDrawer('drawer_obcy_1', 'kadry');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $results = $service->search(Actor::human(self::OWNER), new MemoryQuery('cokolwiek'));

        self::assertSame(
            ['drawer_alfa_1'],
            array_map(static fn ($f): string => $f->id->value, $results),
        );
    }

    public function testDrawerUnknownToTheRegistryIsDropped(): void
    {
        $this->palace->given('wing_alfa', 'drawer_nieznany', 'szuflada bez wpisu w rejestrze');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        self::assertSame([], $service->search(Actor::human(self::OWNER), new MemoryQuery('cokolwiek')));
    }

    public function testResultsAreOrderedByRelevanceAcrossSpacesAndCappedAtTheLimit(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'słabsze', similarity: 0.40);
        $this->palace->given('wing_beta', 'drawer_beta_1', 'najlepsze', similarity: 0.90);
        $this->palace->given('wing_beta', 'drawer_beta_2', 'średnie', similarity: 0.60);
        foreach (['drawer_alfa_1' => 'alfa', 'drawer_beta_1' => 'beta', 'drawer_beta_2' => 'beta'] as $id => $space) {
            $this->registerDrawer($id, $space);
        }

        $service = $this->serviceFor([
            self::OWNER => ['alfa' => SpaceRole::Reader, 'beta' => SpaceRole::Reader],
        ]);

        $results = $service->search(Actor::human(self::OWNER), new MemoryQuery('cokolwiek', limit: 2));

        self::assertSame(
            ['drawer_beta_1', 'drawer_beta_2'],
            array_map(static fn ($f): string => $f->id->value, $results),
            'merging several wings must re-rank, not concatenate',
        );
    }

    public function testEveryReturnedFragmentCarriesTheSpaceItWasAuthorisedIn(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'treść');
        $this->registerDrawer('drawer_alfa_1', 'alfa');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);
        $results = $service->search(Actor::human(self::OWNER), new MemoryQuery('cokolwiek'));

        self::assertNotSame([], $results);
        self::assertSame('alfa', $results[0]->space?->value);
    }

    public function testGetRefusesADrawerFromAForbiddenSpace(): void
    {
        $this->palace->given('wing_kadry', 'drawer_kadry_1', 'poufna treść');
        $this->registerDrawer('drawer_kadry_1', 'kadry');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        self::assertNull($service->get(Actor::human(self::OWNER), new DrawerId('drawer_kadry_1')));
    }

    public function testAForbiddenDrawerAnswersIdenticallyToOneThatDoesNotExist(): void
    {
        // Same reasoning as the space endpoints: "no access" and "no such
        // thing" must be indistinguishable, or the answer itself confirms that
        // the content exists.
        $this->palace->given('wing_kadry', 'drawer_kadry_1', 'poufna treść');
        $this->registerDrawer('drawer_kadry_1', 'kadry');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);
        $actor = Actor::human(self::OWNER);

        self::assertSame(
            $service->get($actor, new DrawerId('drawer_nie_istnieje')),
            $service->get($actor, new DrawerId('drawer_kadry_1')),
        );
    }

    public function testGetReturnsContentFromAnAllowedSpace(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'nasza treść');
        $this->registerDrawer('drawer_alfa_1', 'alfa');

        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);
        $fragment = $service->get(Actor::human(self::OWNER), new DrawerId('drawer_alfa_1'));

        self::assertNotNull($fragment);
        self::assertSame('nasza treść', $fragment->content);
        self::assertSame('alfa', $fragment->space?->value);
    }

    // ---------------------------------------------------------------- writing

    public function testWriteWithoutASpaceLandsInTheActorsPrivateSpace(): void
    {
        $service = $this->serviceFor(
            [self::OWNER => ['alfa' => SpaceRole::Writer, 'priv_user-1' => SpaceRole::Admin]],
            privateSpaceOwner: self::OWNER,
            privateSpaceSlug: 'priv_user-1',
        );

        $stored = $service->remember(Actor::human(self::OWNER), 'ustalenie bez wskazanej przestrzeni');

        self::assertSame('wing_priv_user-1', $this->palace->writes[0]['wing']);
        self::assertSame('priv_user-1', $this->registry->rows[$stored->drawer->value]->space->value);
        // Reported back, not only recorded: a caller that named no space has no
        // other way to learn where its own write went.
        self::assertSame('priv_user-1', $stored->space->value);
    }

    public function testAgentWriteWithoutASpaceLandsInTheOwnersPrivateSpace(): void
    {
        $service = $this->serviceFor(
            [self::OWNER => ['alfa' => SpaceRole::Writer, 'priv_user-1' => SpaceRole::Admin]],
            privateSpaceOwner: self::OWNER,
            privateSpaceSlug: 'priv_user-1',
        );

        $agent = Actor::agent(self::OWNER, 'token-1');
        $stored = $service->remember($agent, 'ustalenie agenta');

        self::assertSame('priv_user-1', $stored->space->value);
        self::assertSame('token-1', $this->registry->rows[$stored->drawer->value]->author->agentTokenId);
    }

    public function testReaderCannotWrite(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $this->expectException(MemoryAccessDenied::class);
        $service->remember(Actor::human(self::OWNER), 'treść', new SpaceId('alfa'));
    }

    public function testWritingToAForbiddenSpaceStoresNothing(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        try {
            $service->remember(Actor::human(self::OWNER), 'treść', new SpaceId('kadry'));
            self::fail('a write outside the actor permissions must be refused');
        } catch (MemoryAccessDenied) {
            self::assertSame([], $this->palace->writes);
            self::assertSame([], $this->registry->rows);
        }
    }

    public function testTheAuthorLabelSentToThePalaceIsSafeAsAPathSegment(): void
    {
        // MemPalace files a diary entry under this label as a path segment and
        // refuses anything with a colon or a slash. A live palace found that;
        // this test is what stops it coming back.
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        $service->remember(Actor::agent(self::OWNER, 'token-1'), 'treść', new SpaceId('alfa'));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $this->palace->writes[0]['addedBy']);
        self::assertStringContainsString(self::OWNER, $this->palace->writes[0]['addedBy']);
        self::assertStringContainsString('token-1', $this->palace->writes[0]['addedBy']);
    }

    public function testEveryWriteIsRegistered(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        $stored = $service->remember(Actor::human(self::OWNER), "Tytuł ustalenia\nreszta treści", new SpaceId('alfa'));

        self::assertArrayHasKey($stored->drawer->value, $this->registry->rows);
        $row = $this->registry->rows[$stored->drawer->value];
        self::assertSame('alfa', $row->space->value);
        self::assertSame(MemoryKind::Note, $row->kind);
        self::assertSame('Tytuł ustalenia', $row->title);
        self::assertSame(hash('sha256', "Tytuł ustalenia\nreszta treści"), $row->contentHash);
    }

    public function testFailedBookkeepingRollsBackTheWholeWrite(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);
        $this->registry->failOnRegister = true;

        try {
            $service->remember(Actor::human(self::OWNER), 'treść', new SpaceId('alfa'));
            self::fail('a write whose bookkeeping fails must not report success');
        } catch (\RuntimeException) {
            self::assertSame([], $this->registry->rows, 'no half-written row may survive');
            self::assertSame(1, $this->registry->transactions, 'the write must run inside a transaction');
        }
    }

    public function testWriteToAKindWithoutADrawerIsRefused(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        $this->expectException(\LogicException::class);
        $service->remember(Actor::human(self::OWNER), 'treść', new SpaceId('alfa'), MemoryKind::KgFact);
    }

    public function testDiaryEntryLandsInThePrivateSpaceByDefault(): void
    {
        $service = $this->serviceFor(
            [self::OWNER => ['priv_user-1' => SpaceRole::Admin]],
            privateSpaceOwner: self::OWNER,
            privateSpaceSlug: 'priv_user-1',
        );

        $stored = $service->diaryWrite(Actor::agent(self::OWNER, 'token-1'), 'SESSION:2026-09-12|TODO-003');

        self::assertSame(MemoryKind::Diary, $this->registry->rows[$stored->drawer->value]->kind);
        self::assertSame('priv_user-1', $stored->space->value);
        self::assertSame('wing_priv_user-1', $this->palace->writes[0]['wing']);
    }

    // ------------------------------------------------------- knowledge graph

    public function testFactsAreQueriedUnderAWingQualifiedName(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $service->kgQuery(Actor::human(self::OWNER), 'WS_Memory');

        self::assertSame(
            ['wing_alfa::WS_Memory'],
            $this->palace->factQueries,
            'the graph has no wing filter, so the scope has to be in the key itself',
        );
    }

    public function testFactsFromAForbiddenSpaceAreNotReturned(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);
        $this->palace->givenFact(new KnowledgeFact('wing_kadry::Jan', 'zarabia', 'wing_kadry::dużo'));

        self::assertSame([], $service->kgQuery(Actor::human(self::OWNER), 'Jan'));
        self::assertSame(['wing_alfa::Jan'], $this->palace->factQueries);
    }

    public function testAddedFactIsScopedAndRegistered(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);
        $fact = new KnowledgeFact('WS_Memory', 'uses', 'MemPalace');

        $service->kgAdd(Actor::human(self::OWNER), $fact, new SpaceId('alfa'));

        self::assertSame('wing_alfa::WS_Memory', $this->palace->facts[0]->subject);
        self::assertSame('wing_alfa::MemPalace', $this->palace->facts[0]->object);

        $registered = DrawerId::forFact($fact, new SpaceId('alfa'));
        self::assertArrayHasKey($registered->value, $this->registry->rows);
    }

    public function testAddedFactComesBackForItsOwnSpace(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);
        $actor = Actor::human(self::OWNER);

        $service->kgAdd($actor, new KnowledgeFact('WS_Memory', 'uses', 'MemPalace'), new SpaceId('alfa'));
        $facts = $service->kgQuery($actor, 'WS_Memory');

        self::assertCount(1, $facts);
        self::assertSame('WS_Memory', $facts[0]->subject, 'the wing prefix is an implementation detail and must be stripped');
        self::assertSame('MemPalace', $facts[0]->object);
    }

    public function testReaderCannotAddFacts(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);

        $this->expectException(MemoryAccessDenied::class);
        $service->kgAdd(Actor::human(self::OWNER), new KnowledgeFact('a', 'b', 'c'), new SpaceId('alfa'));
    }

    // ------------------------------------------------------------ unavailable

    public function testUnavailableMemoryFailsLoudlyInsteadOfLookingEmpty(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'treść');
        $this->registerDrawer('drawer_alfa_1', 'alfa');
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Reader]]);
        $this->palace->unavailable = true;

        $this->expectException(MemoryUnavailable::class);
        $service->search(Actor::human(self::OWNER), new MemoryQuery('cokolwiek'));
    }

    public function testUnavailableMemoryLeavesNoRegistryRowBehind(): void
    {
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);
        $this->palace->unavailable = true;

        try {
            $service->remember(Actor::human(self::OWNER), 'treść', new SpaceId('alfa'));
            self::fail('a write that memory refused must not report success');
        } catch (MemoryUnavailable) {
            self::assertSame([], $this->registry->rows);
        }
    }

    // ------------------------------------------------------------------ audit

    public function testReadsAndWritesLeaveAnAuditTrail(): void
    {
        $this->palace->given('wing_alfa', 'drawer_alfa_1', 'treść');
        $this->registerDrawer('drawer_alfa_1', 'alfa');
        $service = $this->serviceFor([self::OWNER => ['alfa' => SpaceRole::Writer]]);
        $actor = Actor::human(self::OWNER);

        $service->search($actor, new MemoryQuery('cokolwiek'));
        $service->get($actor, new DrawerId('drawer_alfa_1'));
        $service->remember($actor, 'nowa treść', new SpaceId('alfa'));

        self::assertSame(
            ['memory.search', 'memory.read', 'memory.remember'],
            $this->audit->actions(),
        );
    }

    // ------------------------------------------------------------------ setup

    /**
     * @param array<string, array<string, SpaceRole>> $memberships
     */
    private function serviceFor(
        array $memberships,
        ?string $privateSpaceOwner = null,
        ?string $privateSpaceSlug = null,
    ): MemoryService {
        $slugs = [];
        foreach ($memberships as $spaces) {
            foreach (array_keys($spaces) as $slug) {
                $slugs[$slug] = 'wing_' . $slug;
            }
        }
        foreach (['kadry', 'beta'] as $slug) {
            $slugs[$slug] ??= 'wing_' . $slug;
        }

        return new MemoryService(
            $this->palace,
            $this->lexical,
            $this->registry,
            new SpaceAccessResolver(new FixedMemberships($memberships)),
            new FakeSpaceCatalog($slugs, $privateSpaceOwner, $privateSpaceSlug),
            $this->audit,
        );
    }

    private function registerDrawer(string $drawerId, string $spaceSlug): void
    {
        $this->registry->givenRow(MemoryWrite::ofContent(
            new DrawerId($drawerId),
            new SpaceId($spaceSlug),
            MemoryKind::Note,
            Actor::human(self::OWNER),
            'treść',
        ));
    }
}
