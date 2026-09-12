<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The wiki: revisions, rollback, verification and who may do what.
 *
 * Needs no palace. Publishing is dispatched to the queue, so these tests assert that
 * a job was **enqueued** and leave what the palace does with it to
 * tests/Integration/WikiOnLivePalaceTest.
 *
 * The tests that matter are the ones about not losing anything: a second write must
 * add a revision rather than replace one, a rollback must move history forward rather
 * than truncate it, and a new revision must clear a verification — because "Anna
 * checked this" stops being true the moment the text changes, and a stale badge is
 * worse than no badge.
 */
final class WikiTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $writer;
    private string $agentToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.messenger_messages, ws.proposals, ws.memory_entries, ws.document_revisions, '
            . 'ws.documents, ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, '
            . 'ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        $this->writer = ($accept)(($issue)('pisarz@web-systems.pl')->plainToken, 'Pisarz', self::PASSWORD);
        $reader = ($accept)(($issue)('czytelnik@web-systems.pl')->plainToken, 'Czytelnik', self::PASSWORD);
        ($accept)(($issue)('obcy@web-systems.pl')->plainToken, 'Obcy', self::PASSWORD);

        $space = new Space('wiedza', 'Wiedza', 'wing_wiedza');
        $queued = new Space('kadry', 'Kadry', 'wing_kadry');
        $queued->setRequiresProposal(true);

        $this->em->persist($space);
        $this->em->persist($queued);
        $this->em->persist(new SpaceMember($space, $this->writer, SpaceRole::Writer));
        $this->em->persist(new SpaceMember($queued, $this->writer, SpaceRole::Writer));
        $this->em->persist(new SpaceMember($space, $reader, SpaceRole::Reader));
        $this->em->flush();

        $this->agentToken = ($container->get(IssueAgentToken::class))($this->writer, 'agent testowy')->plainToken;
    }

    // -------------------------------------------------------------- revisions

    public function testWriteThenReadReturnsTheContent(): void
    {
        $this->write('umowy/najem', 'Najem lokalu', 'Czynsz płatny do 10. dnia miesiąca.');
        self::assertResponseStatusCodeSame(201);

        $this->get('/api/spaces/wiedza/documents/umowy/najem');

        self::assertResponseIsSuccessful();
        self::assertSame('Czynsz płatny do 10. dnia miesiąca.', $this->json()['content']);
        self::assertSame(1, $this->json()['currentRevision']);
    }

    public function testSecondWriteAddsARevisionInsteadOfOverwriting(): void
    {
        $this->write('najem', 'Najem', 'Wersja pierwsza.');
        $this->write('najem', 'Najem', 'Wersja druga.');

        self::assertResponseStatusCodeSame(200, 'druga rewizja to nie utworzenie dokumentu');
        self::assertSame(2, $this->json()['currentRevision']);

        // The old text must still be readable — that is the whole point of a revision.
        $this->get('/api/spaces/wiedza/documents/najem?revision=1');
        self::assertSame('Wersja pierwsza.', $this->json()['content']);
    }

    public function testHistoryListsEveryRevisionWithItsAuthor(): void
    {
        $this->write('najem', 'Najem', 'Pierwsza.');
        $this->write('najem', 'Najem', 'Druga.', 'poprawka stawki');

        $this->get('/api/spaces/wiedza/documents/najem/history');

        $revisions = $this->json()['revisions'];
        self::assertCount(2, $revisions);
        self::assertSame([1, 2], array_column($revisions, 'number'));
        self::assertSame('poprawka stawki', $revisions[1]['changeNote']);
        self::assertFalse($revisions[0]['byAi']);
        self::assertNotNull($revisions[0]['authorUserId']);
    }

    public function testTitleIsRememberedAsItWasAtEachRevision(): void
    {
        // A history showing today's title on every old revision would misrepresent
        // what the document said at the time.
        $this->write('najem', 'Stary tytuł', 'Treść.');
        $this->write('najem', 'Nowy tytuł', 'Treść poprawiona.');

        $this->get('/api/spaces/wiedza/documents/najem/history');

        self::assertSame(['Stary tytuł', 'Nowy tytuł'], array_column($this->json()['revisions'], 'title'));
    }

    public function testDiffBetweenFirstAndThirdRevision(): void
    {
        $this->write('najem', 'Najem', "wiersz A\nwiersz B\nwiersz C");
        $this->write('najem', 'Najem', "wiersz A\nwiersz ZMIENIONY\nwiersz C");
        $this->write('najem', 'Najem', "wiersz A\nwiersz ZMIENIONY\nwiersz C\nwiersz D");

        $this->get('/api/spaces/wiedza/documents/najem/diff?from=1&to=3');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['identical']);
        self::assertSame(2, $this->json()['added'], 'zmieniony wiersz plus dopisany');
        self::assertSame(1, $this->json()['removed']);
    }

    public function testRollbackAddsANewRevisionAndKeepsTheOnesBetween(): void
    {
        $this->write('najem', 'Najem', 'Pierwsza.');
        $this->write('najem', 'Najem', 'Druga.');
        $this->write('najem', 'Najem', 'Trzecia.');

        $this->post('/api/spaces/wiedza/documents/najem/rollback', ['toRevision' => 1]);

        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->json()['currentRevision'], 'cofnięcie idzie do przodu, nie do tyłu');

        $this->get('/api/spaces/wiedza/documents/najem');
        self::assertSame('Pierwsza.', $this->json()['content']);

        // Nothing was deleted. A history that can shrink is not a history.
        $this->get('/api/spaces/wiedza/documents/najem/history');
        self::assertSame([1, 2, 3, 4], array_column($this->json()['revisions'], 'number'));

        $this->get('/api/spaces/wiedza/documents/najem?revision=3');
        self::assertSame('Trzecia.', $this->json()['content']);
    }

    public function testRollbackToARevisionThatDoesNotExistIsRefused(): void
    {
        $this->write('najem', 'Najem', 'Pierwsza.');

        $this->post('/api/spaces/wiedza/documents/najem/rollback', ['toRevision' => 7]);

        self::assertResponseStatusCodeSame(404);
    }

    // ----------------------------------------------------------- verification

    public function testHumanCanVerifyAndANewRevisionClearsIt(): void
    {
        $this->write('najem', 'Najem', 'Treść sprawdzona.');
        $this->post('/api/spaces/wiedza/documents/najem/verify');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['verified']);
        self::assertSame('Pisarz', $this->json()['verifiedBy']);

        $this->write('najem', 'Najem', 'Treść zmieniona po weryfikacji.');

        self::assertFalse(
            $this->json()['verified'],
            '„Anna to sprawdziła" przestaje być prawdą w chwili zmiany tekstu',
        );
    }

    public function testDocumentWrittenByAnAgentIsMarkedAndUnverified(): void
    {
        $this->tool('ws_doc_write', [
            'space' => 'wiedza',
            'slug' => 'od-agenta',
            'title' => 'Ustalenie agenta',
            'content' => 'Backup bazy robimy co noc o 3:00.',
        ]);

        $this->get('/api/spaces/wiedza/documents/od-agenta');

        self::assertTrue($this->json()['authoredByAi']);
        self::assertFalse($this->json()['verified']);
        self::assertTrue($this->json()['revision']['byAi']);
        self::assertNotNull($this->json()['revision']['authorAgentTokenId']);
    }

    public function testAgentHasNoWayToVerifyAtAll(): void
    {
        // Not "is refused": the verify route authenticates with a JWT, and an agent
        // token is not one. The tool set has no ws_doc_verify either, so there is no
        // path — which is what D-005 asks for.
        $this->write('najem', 'Najem', 'Treść.');

        $this->client->request('POST', '/api/spaces/wiedza/documents/najem/verify', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->agentToken,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    // ------------------------------------------------------------ permissions

    public function testReaderCannotWrite(): void
    {
        $this->client->request(
            'PUT',
            '/api/spaces/wiedza/documents/najem',
            server: $this->authAs('czytelnik@web-systems.pl'),
            content: json_encode(['title' => 'Najem', 'content' => 'Treść.'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testReaderCanRead(): void
    {
        $this->write('najem', 'Najem', 'Treść.');

        $this->client->request('GET', '/api/spaces/wiedza/documents/najem', server: $this->authAs('czytelnik@web-systems.pl'));

        self::assertResponseIsSuccessful();
    }

    public function testStrangerGetsNotFoundRatherThanForbidden(): void
    {
        // 403 would confirm that a document at this address exists.
        $this->write('najem', 'Najem', 'Treść.');

        $this->client->request('GET', '/api/spaces/wiedza/documents/najem', server: $this->authAs('obcy@web-systems.pl'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testStrangerSeesAnEmptyListRatherThanAnError(): void
    {
        $this->write('najem', 'Najem', 'Treść.');

        $this->client->request('GET', '/api/spaces/wiedza/documents', server: $this->authAs('obcy@web-systems.pl'));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['documents']);
    }

    public function testAgentCannotReadDocumentsFromASpaceOutsideItsScope(): void
    {
        $container = static::getContainer();
        $narrow = ($container->get(IssueAgentToken::class))($this->writer, 'wąski', ['kadry'])->plainToken;

        $this->write('najem', 'Najem', 'Treść.');

        $payload = $this->tool('ws_doc_read', ['space' => 'wiedza', 'slug' => 'najem'], $narrow);

        self::assertFalse($payload['found'], 'zakres tokena może tylko zawężać');
    }

    // ----------------------------------------------------------------- adresy

    public function testMalformedSlugIsRefusedRatherThanTidiedUp(): void
    {
        $this->client->request(
            'PUT',
            '/api/spaces/wiedza/documents/Umowy Najmu',
            server: $this->authAs('pisarz@web-systems.pl'),
            content: json_encode(['title' => 'Najem', 'content' => 'Treść.'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testSlugWithASlashIsReachable(): void
    {
        // Without the {slug<.+>} requirement Symfony stops at the first segment and
        // a folder-shaped address becomes unreachable.
        $this->write('wdrozenia/backup-bazy', 'Backup bazy', 'pg_dump raz na dobę.');

        $this->get('/api/spaces/wiedza/documents/wdrozenia/backup-bazy');

        self::assertResponseIsSuccessful();
        self::assertSame('wdrozenia/backup-bazy', $this->json()['slug']);
    }

    // ------------------------------------------------------ kolejka propozycji

    public function testAgentWriteInAQueuedSpaceLandsInTheQueueNotInTheWiki(): void
    {
        $answer = $this->rpcTool('ws_doc_write', [
            'space' => 'kadry',
            'slug' => 'premie',
            'title' => 'Zasady premii',
            'content' => 'Premia kwartalna zależy od wyniku zespołu.',
        ], $this->agentToken);

        self::assertSame(-32004, $answer['error']['code']);
        self::assertStringContainsString('ws_propose', $answer['error']['message'], 'błąd musi nazwać drogę dalej');

        // And nothing reached the wiki.
        $this->get('/api/spaces/kadry/documents/premie');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHumanWritesDirectlyEvenInAQueuedSpace(): void
    {
        // The queue is for agents. A person writing there is the reviewer, not
        // somebody to be reviewed — queueing them would leave nobody to empty it.
        $this->write('premie', 'Zasady premii', 'Treść od człowieka.', null, 'kadry');

        self::assertResponseStatusCodeSame(201);
    }

    public function testProposalFromAnAgentWaitsForAPersonAndThenBecomesARevision(): void
    {
        $proposed = $this->tool('ws_propose', [
            'space' => 'kadry',
            'title' => 'Zasady premii',
            'content' => 'Premia kwartalna zależy od wyniku zespołu.',
            'slug' => 'premie',
        ]);

        self::assertFalse($proposed['in_wiki'], 'propozycja nie jest jeszcze w wiki i trzeba to powiedzieć wprost');

        $this->get('/api/spaces/kadry/proposals');
        self::assertSame(1, $this->json()['count']);
        self::assertTrue($this->json()['proposals'][0]['byAi']);

        $this->post('/api/proposals/' . $proposed['id'] . '/accept', []);
        self::assertResponseIsSuccessful();

        $this->get('/api/spaces/kadry/documents/premie');
        self::assertResponseIsSuccessful();
        self::assertSame('Premia kwartalna zależy od wyniku zespołu.', $this->json()['content']);

        // The reviewer authored the revision; that the text came from an agent is
        // kept in the change note rather than lost.
        self::assertFalse($this->json()['revision']['byAi']);
        self::assertStringContainsString('od agenta AI', (string) $this->json()['revision']['changeNote']);
    }

    public function testProposalCannotBeAcceptedTwice(): void
    {
        $proposed = $this->tool('ws_propose', [
            'space' => 'kadry',
            'title' => 'Zasady premii',
            'content' => 'Treść.',
            'slug' => 'premie',
        ]);

        $this->post('/api/proposals/' . $proposed['id'] . '/accept', []);
        self::assertResponseIsSuccessful();

        $this->post('/api/proposals/' . $proposed['id'] . '/accept', []);
        self::assertResponseStatusCodeSame(404, 'rozpatrzona propozycja jest dla API nieistniejąca');
    }

    public function testRejectedProposalNeverBecomesADocument(): void
    {
        $proposed = $this->tool('ws_propose', [
            'space' => 'kadry',
            'title' => 'Zasady premii',
            'content' => 'Treść.',
            'slug' => 'premie',
        ]);

        $this->post('/api/proposals/' . $proposed['id'] . '/reject', ['note' => 'nieaktualne']);

        self::assertSame('rejected', $this->json()['status']);
        self::assertSame('nieaktualne', $this->json()['reviewNote']);

        $this->get('/api/spaces/kadry/documents/premie');
        self::assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------- publikacja

    public function testEveryWriteEnqueuesExactlyOnePublishJob(): void
    {
        // The palace is not touched here — the job is. A write that enqueued nothing
        // would leave the document invisible to search with nothing to notice.
        $this->write('najem', 'Najem', 'Pierwsza.');
        self::assertSame(1, $this->queuedJobs());

        $this->write('najem', 'Najem', 'Druga.');
        self::assertSame(2, $this->queuedJobs());

        $this->post('/api/spaces/wiedza/documents/najem/rollback', ['toRevision' => 1]);
        self::assertSame(3, $this->queuedJobs(), 'cofnięcie też zmienia treść, więc też wymaga publikacji');
    }

    public function testArchivingKeepsTheDocumentReadableByItsAddress(): void
    {
        $this->write('najem', 'Najem', 'Treść.');
        $this->post('/api/spaces/wiedza/documents/najem/archive');

        self::assertTrue($this->json()['archived']);

        // Archived, not deleted: history never shrinks.
        $this->get('/api/spaces/wiedza/documents/najem');
        self::assertResponseIsSuccessful();

        $this->get('/api/spaces/wiedza/documents');
        self::assertSame([], $this->json()['documents'], 'domyślna lista pomija zarchiwizowane');

        $this->get('/api/spaces/wiedza/documents?archived=1');
        self::assertCount(1, $this->json()['documents']);
    }

    public function testWritingToAnArchivedDocumentRestoresIt(): void
    {
        $this->write('najem', 'Najem', 'Treść.');
        $this->post('/api/spaces/wiedza/documents/najem/archive');

        $this->write('najem', 'Najem', 'Treść wraca.');

        self::assertFalse($this->json()['archived'], 'zapis do zarchiwizowanego przywraca go — o to prosi sam zapis');
        self::assertSame(2, $this->json()['currentRevision']);
    }

    // ------------------------------------------------------------------ setup

    private function queuedJobs(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM ws.messenger_messages WHERE body LIKE '%PublishDocument%'"
        );
    }

    private function write(
        string $slug,
        string $title,
        string $content,
        ?string $changeNote = null,
        string $space = 'wiedza',
    ): void {
        $payload = ['title' => $title, 'content' => $content];
        if (null !== $changeNote) {
            $payload['changeNote'] = $changeNote;
        }

        $this->client->request(
            'PUT',
            \sprintf('/api/spaces/%s/documents/%s', $space, $slug),
            server: $this->authAs('pisarz@web-systems.pl'),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function get(string $path): void
    {
        $this->client->request('GET', $path, server: $this->authAs('pisarz@web-systems.pl'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload = []): void
    {
        $this->client->request(
            'POST',
            $path,
            server: $this->authAs('pisarz@web-systems.pl'),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments, ?string $token = null): array
    {
        $answer = $this->rpcTool($name, $arguments, $token ?? $this->agentToken);

        self::assertArrayNotHasKey(
            'error',
            $answer,
            $name . ': ' . (string) json_encode($answer['error'] ?? null, \JSON_UNESCAPED_UNICODE),
        );

        $payload = json_decode((string) ($answer['result']['content'][0]['text'] ?? ''), true);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function rpcTool(string $name, array $arguments, string $token): array
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ], \JSON_THROW_ON_ERROR),
        );

        return $this->json();
    }

    /** @return array<string, string> */
    private function authAs(string $email): array
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );

        $token = $this->json()['token'] ?? null;
        self::assertIsString($token, 'logowanie nie zwróciło tokena');

        return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'odpowiedź nie jest JSON-em: ' . $content);

        return $decoded;
    }
}
