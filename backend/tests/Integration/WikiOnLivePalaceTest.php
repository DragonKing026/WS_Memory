<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use App\Infrastructure\MemPalace\MemPalaceClient;
use App\Infrastructure\MemPalace\MemPalaceHealthProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The wiki reaching the palace: publication, replacement and the race.
 *
 * WikiTest proves revisions and permissions without a palace, which is what makes it
 * cheap. This file proves the two things that cannot be faked:
 *
 *  - a document written in the wiki becomes findable by **meaning**, in Polish, by
 *    words it does not contain;
 *  - publishing a second revision **replaces** the first in the palace rather than
 *    adding to it. Without that, an agent searching would find superseded text with
 *    no way to tell — the failure that would make the whole wiki untrustworthy, and
 *    one that no double can demonstrate, because it depends on what MemPalace's
 *    update actually does to the stored vector.
 *
 * The queue is drained through the real transport, including once in reverse order,
 * because "three quick saves end with the newest text in the palace" is a property of
 * the ordering guard and not of the queue being polite.
 */
#[Group('integracja')]
final class WikiOnLivePalaceTest extends WebTestCase
{
    use RequiresLivePalace;

    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    /** Held only so tearDown can take back what this test put into the palace. */
    private MemPalaceClient $palace;
    private EntityManagerInterface $em;
    private Connection $connection;
    private string $wing;
    private string $agentToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $palace = $container->get(MemPalaceClient::class);
        self::assertInstanceOf(MemPalaceClient::class, $palace);
        $this->palace = $palace;

        $probe = $container->get(MemPalaceHealthProbe::class);
        self::assertInstanceOf(MemPalaceHealthProbe::class, $probe);
        self::skipUnlessPalaceAnswers($probe);

        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(
            'TRUNCATE ws.messenger_messages, ws.proposals, ws.memory_entries, ws.document_revisions, '
            . 'ws.documents, ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, '
            . 'ws.spaces, ws.users CASCADE'
        );

        // A wing of its own per run: a shared one accumulates near-identical content
        // and the palace applies its own limit before we see anything.
        $this->wing = 'test-wiki-' . bin2hex(random_bytes(4));

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);
        $writer = ($accept)(($issue)('pisarz@web-systems.pl')->plainToken, 'Pisarz', self::PASSWORD);

        $space = new Space('wiedza', 'Wiedza', $this->wing);
        $this->em->persist($space);
        $this->em->persist(new SpaceMember($space, $writer, SpaceRole::Writer));
        $this->em->flush();

        $this->agentToken = ($container->get(IssueAgentToken::class))($writer, 'agent testowy')->plainToken;
    }

    protected function tearDown(): void
    {
        // Guarded on the wing because it is set after the skip: with no palace there
        // is nothing to clean and no client to clean it with.
        if (isset($this->wing)) {
            $this->deleteDrawersFiledByThisTest($this->connection, $this->palace, $this->wing);
        }

        parent::tearDown();
    }

    public function testDocumentBecomesFindableByMeaningOnceTheQueueIsDrained(): void
    {
        $this->write('wdrozenia/backup', 'Kopia zapasowa bazy', 'Pełny zrzut bazy wykonujemy każdej nocy o trzeciej.');

        self::assertSame(1, $this->drainQueue(), 'zapis musi zostawić dokładnie jedno zlecenie publikacji');

        $found = $this->tool('ws_search', ['query' => 'jak często robimy zabezpieczenie danych', 'limit' => 20]);

        self::assertGreaterThan(0, $found['count'], 'dokument z wiki musi dać się znaleźć znaczeniem');
        self::assertStringContainsString(
            'każdej nocy o trzeciej',
            implode("\n", array_column($found['results'], 'content')),
        );
    }

    public function testSecondRevisionReplacesTheFirstInThePalaceRatherThanJoiningIt(): void
    {
        $this->write('wdrozenia/backup', 'Kopia zapasowa bazy', 'Zrzut bazy wykonujemy o trzeciej nad ranem.');
        $this->drainQueue();

        $this->write('wdrozenia/backup', 'Kopia zapasowa bazy', 'Zrzut bazy wykonujemy o dwudziestej drugiej wieczorem.');
        $this->drainQueue();

        // One registry row, therefore one drawer: the schema forbids a second, and
        // this asserts the publication path respects it rather than failing.
        self::assertSame(1, $this->registryRowsForDocuments());

        $found = $this->tool('ws_search', ['query' => 'o której godzinie zrzut bazy', 'limit' => 20]);
        $content = implode("\n", array_column($found['results'], 'content'));

        self::assertStringContainsString('dwudziestej drugiej', $content, 'nowa treść musi być wyszukiwalna');
        self::assertStringNotContainsString(
            'trzeciej nad ranem',
            $content,
            'stara treść nie może zostać w pałacu — agent nie ma jak poznać, że jest nieaktualna',
        );
    }

    public function testStaleJobDoesNotOverwriteTheNewestRevision(): void
    {
        // Three quick saves, drained newest first. That order is the point: a late
        // older message must be dropped, not published, or the palace would end up
        // holding superseded text while the wiki shows the current one.
        $this->write('najem', 'Najem', 'Stawka czynszu wynosi tysiąc złotych.');
        $this->write('najem', 'Najem', 'Stawka czynszu wynosi tysiąc dwieście złotych.');
        $this->write('najem', 'Najem', 'Stawka czynszu wynosi tysiąc pięćset złotych.');

        self::assertSame(3, $this->drainQueue(newestFirst: true));

        $fetched = $this->tool('ws_doc_read', ['space' => 'wiedza', 'slug' => 'najem']);
        self::assertSame(3, $fetched['revision']);

        $found = $this->tool('ws_search', ['query' => 'ile wynosi opłata za wynajem', 'limit' => 20]);
        $content = implode("\n", array_column($found['results'], 'content'));

        self::assertStringContainsString('tysiąc pięćset', $content);
        self::assertStringNotContainsString('tysiąc dwieście', $content);
        self::assertStringNotContainsString('tysiąc złotych', $content);
    }

    public function testRollbackIsPublishedLikeAnyOtherChange(): void
    {
        $this->write('najem', 'Najem', 'Umowa na czas nieokreślony.');
        $this->drainQueue();
        $this->write('najem', 'Najem', 'Umowa na czas określony, dwa lata.');
        $this->drainQueue();

        $this->post('/api/spaces/wiedza/documents/najem/rollback', ['toRevision' => 1]);
        self::assertSame(1, $this->drainQueue());

        $found = $this->tool('ws_search', ['query' => 'na jaki okres zawarta umowa', 'limit' => 20]);
        $content = implode("\n", array_column($found['results'], 'content'));

        self::assertStringContainsString('nieokreślony', $content);
        self::assertStringNotContainsString('dwa lata', $content, 'cofnięcie musi dotrzeć do pałaca jak każda zmiana');
    }

    public function testDocumentWrittenByAnAgentThroughMcpReachesThePalaceToo(): void
    {
        $this->tool('ws_doc_write', [
            'space' => 'wiedza',
            'slug' => 'zasady-kodu',
            'title' => 'Zasady pisania kodu',
            'content' => 'Testy negatywne piszemy przed implementacją reguł uprawnień.',
            'change_note' => 'pierwsza wersja',
        ]);

        self::assertSame(1, $this->drainQueue());

        $found = $this->tool('ws_search', ['query' => 'kolejność pracy przy uprawnieniach', 'limit' => 20]);

        self::assertGreaterThan(0, $found['count']);
    }

    public function testPublishedDrawerIsRegisteredAgainstItsDocument(): void
    {
        $this->write('najem', 'Najem', 'Treść dokumentu.');
        $this->drainQueue();

        $documentId = (string) $this->connection->fetchOne(
            "SELECT id FROM ws.documents WHERE slug = 'najem'"
        );

        $registry = static::getContainer()->get(MemoryRegistry::class);
        self::assertInstanceOf(MemoryRegistry::class, $registry);

        $drawer = $registry->drawerForDocument($documentId);
        self::assertNotNull($drawer, 'bez wiersza w rejestrze druga warstwa filtrowania odrzuci każdy wynik');

        $row = $this->connection->fetchAssociative(
            'SELECT kind, title FROM ws.memory_entries WHERE document_id = :id',
            ['id' => $documentId],
        );
        self::assertIsArray($row);
        self::assertSame('document', $row['kind']);
        // The document's own title, not the first line of its content.
        self::assertSame('Najem', $row['title']);
    }

    // ------------------------------------------------------------------ setup

    /**
     * Works the queue the way the worker does, optionally in the worst order.
     *
     * Through the real transport rather than by calling the handler: the ordering
     * guard is a property of what the worker sees, and calling the handler directly
     * would test the guard against arguments this test chose itself.
     */
    private function drainQueue(bool $newestFirst = false): int
    {
        $container = static::getContainer();
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        /** @var list<Envelope> $envelopes */
        $envelopes = [];
        while (true) {
            $batch = [];
            foreach ($transport->get() as $envelope) {
                $batch[] = $envelope;
            }

            if ([] === $batch) {
                break;
            }

            foreach ($batch as $envelope) {
                $envelopes[] = $envelope;
            }
        }

        if ($newestFirst) {
            $envelopes = array_reverse($envelopes);
        }

        foreach ($envelopes as $envelope) {
            // ReceivedStamp so the bus handles it here instead of queueing it again.
            $bus->dispatch($envelope->getMessage(), [new ReceivedStamp('async')]);
            $transport->ack($envelope);
        }

        return \count($envelopes);
    }

    private function registryRowsForDocuments(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM ws.memory_entries WHERE document_id IS NOT NULL'
        );
    }

    private function write(string $slug, string $title, string $content): void
    {
        $this->client->request(
            'PUT',
            '/api/spaces/wiedza/documents/' . $slug,
            server: $this->authAsWriter(),
            content: json_encode(['title' => $title, 'content' => $content], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload): void
    {
        $this->client->request(
            'POST',
            $path,
            server: $this->authAsWriter(),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->agentToken],
            content: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ], \JSON_THROW_ON_ERROR),
        );

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertArrayNotHasKey(
            'error',
            $answer,
            $name . ': ' . (string) json_encode($answer['error'] ?? null, \JSON_UNESCAPED_UNICODE),
        );

        $payload = json_decode((string) ($answer['result']['content'][0]['text'] ?? ''), true);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return array<string, string> */
    private function authAsWriter(): array
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'pisarz@web-systems.pl', 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertIsString($answer['token'] ?? null);

        return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $answer['token']];
    }
}
