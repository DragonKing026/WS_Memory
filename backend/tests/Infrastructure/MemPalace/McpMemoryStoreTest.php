<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemPalace;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\PalaceWing;
use App\Infrastructure\MemPalace\McpMemoryStore;
use App\Infrastructure\MemPalace\MemPalaceClient;
use App\Infrastructure\MemPalace\MemPalaceUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The translation between our vocabulary and MemPalace's.
 *
 * Asserted against the field names a live MemPalace 3.7.0 actually returns
 * (`drawer_id`, `text`, `similarity`, `bm25_score` for a search; `content` and
 * `metadata` for a single drawer) rather than against what the documentation
 * implies. The two differ, which is the whole reason this file exists.
 */
final class McpMemoryStoreTest extends TestCase
{
    /** @var list<array{tool: string, arguments: array<string, mixed>}> */
    private array $sent = [];

    public function testSearchAlwaysSendsTheWing(): void
    {
        $store = $this->storeAnswering([$this->envelope(['results' => []])]);

        $store->search(new PalaceWing('wing_alfa'), new MemoryQuery('umowa najmu'));

        self::assertSame('mempalace_search', $this->sent[0]['tool']);
        self::assertSame('wing_alfa', $this->sent[0]['arguments']['wing'] ?? null);
    }

    public function testSearchTranslatesKindIntoARoomFilter(): void
    {
        $store = $this->storeAnswering([$this->envelope(['results' => []])]);

        $store->search(new PalaceWing('wing_alfa'), new MemoryQuery('cokolwiek', kind: MemoryKind::Document));

        self::assertSame('documentation', $this->sent[0]['arguments']['room'] ?? null);
    }

    public function testSearchResultsAreMappedFromTheFieldsMemPalaceReallySends(): void
    {
        $store = $this->storeAnswering([$this->envelope(['results' => [[
            'drawer_id' => 'drawer_alfa_technical_abc',
            'text' => 'Umowa najmu wymaga aneksu',
            'wing' => 'wing_alfa',
            'room' => 'technical',
            'source_file' => 'umowa.md',
            'source_path' => '/projekt/umowa.md',
            'created_at' => '2026-09-12T20:48:46.501437',
            'similarity' => 0.74,
            'bm25_score' => 0.0,
        ]]])]);

        $fragments = $store->search(new PalaceWing('wing_alfa'), new MemoryQuery('czynsz'));

        self::assertCount(1, $fragments);
        self::assertSame('drawer_alfa_technical_abc', $fragments[0]->id->value);
        self::assertSame('Umowa najmu wymaga aneksu', $fragments[0]->content);
        self::assertSame(0.74, $fragments[0]->similarity);
        self::assertSame(0.0, $fragments[0]->lexicalScore);
        self::assertSame('/projekt/umowa.md', $fragments[0]->sourceFile);
        self::assertSame('2026-09-12', $fragments[0]->filedAt?->format('Y-m-d'));
    }

    public function testAResultWithoutAnIdentifierIsSkippedRatherThanGuessedAt(): void
    {
        $store = $this->storeAnswering([$this->envelope(['results' => [
            ['text' => 'treść bez identyfikatora'],
            ['drawer_id' => 'drawer_1', 'text' => 'treść z identyfikatorem'],
        ]])]);

        $fragments = $store->search(new PalaceWing('wing_alfa'), new MemoryQuery('cokolwiek'));

        self::assertCount(1, $fragments);
        self::assertSame('drawer_1', $fragments[0]->id->value);
    }

    public function testFetchReadsContentAndMetadata(): void
    {
        $store = $this->storeAnswering([$this->envelope([
            'drawer_id' => 'drawer_alfa_technical_abc',
            'content' => 'pełna treść szuflady',
            'wing' => 'wing_alfa',
            'room' => 'technical',
            'metadata' => ['added_by' => 'ws_user-1', 'filed_at' => '2026-09-12T20:48:46'],
        ])]);

        $fragment = $store->fetch(new DrawerId('drawer_alfa_technical_abc'));

        self::assertNotNull($fragment);
        self::assertSame('pełna treść szuflady', $fragment->content);
        self::assertSame('wing_alfa', $fragment->wing->value);
        self::assertSame('ws_user-1', $fragment->addedBy);
    }

    public function testFetchAnswersNullForADrawerThatIsNotThere(): void
    {
        $store = $this->storeAnswering([$this->envelope(['error' => 'Drawer not found'])]);

        self::assertNull($store->fetch(new DrawerId('drawer_nieznany')));
    }

    public function testWriteSendsTheRoomForItsKindAndTheAuthorFromTheServer(): void
    {
        $store = $this->storeAnswering([$this->envelope(['drawer_id' => 'drawer_alfa_technical_new'])]);

        $drawer = $store->store(
            new PalaceWing('wing_alfa'),
            MemoryKind::Note,
            'ustalenie',
            'ws_user-1__token-1',
        );

        self::assertSame('drawer_alfa_technical_new', $drawer->value);
        self::assertSame([
            'wing' => 'wing_alfa',
            'room' => 'technical',
            'content' => 'ustalenie',
            'added_by' => 'ws_user-1__token-1',
        ], $this->sent[0]['arguments']);
    }

    public function testWriteWithoutAnIdentifierInTheAnswerFails(): void
    {
        // The content is in the palace at this point, but it cannot be booked.
        // Reporting success would leave it permanently invisible — the second
        // filtering layer drops whatever the registry does not know.
        $store = $this->storeAnswering([$this->envelope(['status' => 'filed'])]);

        $this->expectException(MemPalaceUnavailable::class);
        $store->store(new PalaceWing('wing_alfa'), MemoryKind::Note, 'ustalenie', 'ws_user-1');
    }

    public function testDiaryEntryIsFiledIntoTheGivenWing(): void
    {
        // Without an explicit wing the palace files diaries into
        // wing_{agent_name}, outside every space mapping we have.
        $store = $this->storeAnswering([$this->envelope(['drawer_id' => 'drawer_alfa_diary_1'])]);

        $store->writeDiary(new PalaceWing('wing_alfa'), 'ws_user-1', 'SESSION:2026-09-12|TODO-003', 'todo');

        self::assertSame('wing_alfa', $this->sent[0]['arguments']['wing'] ?? null);
        self::assertSame('ws_user-1', $this->sent[0]['arguments']['agent_name'] ?? null);
        self::assertSame('todo', $this->sent[0]['arguments']['topic'] ?? null);
    }

    public function testDiaryEntryIdentifierIsReadFromItsOwnField(): void
    {
        // A live palace answers diary_write with `entry_id`, not `drawer_id`.
        // Without this the entry would be filed and then never booked, and an
        // unbooked drawer is one the second filtering layer always drops.
        $store = $this->storeAnswering([$this->envelope([
            'success' => true,
            'entry_id' => 'diary_wing_alfa_20260912_191727_abc',
            'agent' => 'ws_user-1',
        ])]);

        $drawer = $store->writeDiary(new PalaceWing('wing_alfa'), 'ws_user-1', 'SESSION:2026-09-12');

        self::assertSame('diary_wing_alfa_20260912_191727_abc', $drawer->value);
    }

    public function testFactsAreMappedAndHalfFactsSkipped(): void
    {
        $store = $this->storeAnswering([$this->envelope(['facts' => [
            ['subject' => 'WS_Memory', 'predicate' => 'uses', 'object' => 'MemPalace', 'valid_from' => '2026-09-12'],
            ['subject' => 'WS_Memory', 'predicate' => 'uses'],
        ]])]);

        $facts = $store->queryFacts('WS_Memory');

        self::assertCount(1, $facts);
        self::assertSame('MemPalace', $facts[0]->object);
        self::assertSame('2026-09-12', $facts[0]->validFrom?->format('Y-m-d'));
    }

    public function testAddedFactSendsItsValidityWindow(): void
    {
        $store = $this->storeAnswering([$this->envelope(['added' => true])]);

        $store->addFact(new KnowledgeFact(
            'WS_Memory',
            'uses',
            'PostgreSQL 18',
            new \DateTimeImmutable('2026-09-12'),
        ));

        self::assertSame([
            'subject' => 'WS_Memory',
            'predicate' => 'uses',
            'object' => 'PostgreSQL 18',
            'valid_from' => '2026-09-12',
        ], $this->sent[0]['arguments']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function envelope(array $payload): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => json_encode($payload, \JSON_THROW_ON_ERROR)]]],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $responses
     */
    private function storeAnswering(array $responses): McpMemoryStore
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            /** @var array{params: array{name: string, arguments: array<string, mixed>}} $request */
            $request = json_decode((string) ($options['body'] ?? '{}'), true, flags: \JSON_THROW_ON_ERROR);
            $this->sent[] = [
                'tool' => $request['params']['name'],
                'arguments' => $request['params']['arguments'],
            ];

            return new MockResponse(array_shift($queue) ?? '{}');
        });

        return new McpMemoryStore(
            new MemPalaceClient($http, 'http://mempalace:8765', 'token', new NullLogger(), timeoutSeconds: 1.0),
        );
    }
}
