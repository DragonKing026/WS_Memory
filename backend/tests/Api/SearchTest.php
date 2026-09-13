<?php

declare(strict_types=1);

namespace App\Tests\Api;

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
 * Searching, through the door people use.
 *
 * Lexical mode only, and deliberately: it runs entirely on our own tables, so
 * these tests need no palace and can assert exact results rather than "something
 * came back". Semantic mode is a vector query against a live service and belongs
 * in tests/Integration.
 *
 * The two things worth guarding here are the ones that would fail quietly:
 * a search reaching into a space the user cannot read, and a query for an
 * identifier finding nothing because of how Postgres tokenises it.
 */
final class SearchTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $writer;

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
        ($accept)(($issue)('obcy@web-systems.pl')->plainToken, 'Obcy', self::PASSWORD);

        $wiedza = new Space('wiedza', 'Wiedza', 'wing_wiedza');
        $kadry = new Space('kadry', 'Kadry', 'wing_kadry');

        $this->em->persist($wiedza);
        $this->em->persist($kadry);
        $this->em->persist(new SpaceMember($wiedza, $this->writer, SpaceRole::Writer));
        $this->em->persist(new SpaceMember($kadry, $this->writer, SpaceRole::Writer));
        $this->em->flush();
    }

    // ------------------------------------------------------------- finding it

    public function testFindsADocumentByAWordFromItsBody(): void
    {
        $this->write('urlopy', 'Zasady urlopów', 'Wniosek urlopowy składa się w systemie kadrowym.');

        $this->search('urlopowy');

        self::assertResponseIsSuccessful();
        self::assertSame(['Zasady urlopów'], $this->titles());
    }

    public function testTheMatchedWordsComeBackMarked(): void
    {
        $this->write('urlopy', 'Zasady urlopów', 'Wniosek urlopowy składa się w systemie.');

        $this->search('urlopowy');

        self::assertSame(['urlopowy'], $this->matchedWords());
    }

    /**
     * The snippet is a list of parts, never a string of HTML.
     *
     * Content is written by people and by agents, so anything can be in it. The
     * moment a snippet travels as markup, a document containing a script tag is
     * a stored XSS waiting for somebody to render it.
     */
    public function testTheSnippetCarriesNoMarkup(): void
    {
        $this->write('skrypt', 'Notatka', 'Fragment z <script>alert(1)</script> w środku i słowo kluczowe.');

        $this->search('kluczowe');

        $snippet = $this->json()['results'][0]['snippet'];
        self::assertIsArray($snippet);
        foreach ($snippet as $part) {
            self::assertArrayHasKey('text', $part);
            self::assertArrayHasKey('match', $part);
            self::assertIsBool($part['match']);
        }
    }

    /** Typing the beginning of a word finds the whole word (D-030). */
    public function testAPrefixFindsTheLongerWord(): void
    {
        $this->write('architektura', 'Architektura', 'Klasa PalaceWing jest obiektem wartości.');

        $this->search('Palace');

        self::assertSame(['Architektura'], $this->titles());
        self::assertSame(['PalaceWing'], $this->matchedWords());
    }

    /**
     * An identifier with a hyphen has to be findable, and this is not obvious:
     * `simple` reads `D-029` as the lexemes `d` and `-029`, keeping the minus as
     * part of a signed number. A query tokenised by hand into `029` matches
     * nothing at all — which is how "find this exact name" silently stops working
     * for exactly the names people search for.
     */
    public function testFindsAnIdentifierWrittenWithAHyphen(): void
    {
        $this->write('decyzje', 'Decyzje', 'Wyszukiwanie leksykalne opisuje decyzja D-029 w dokumentacji.');

        $this->search('D-029');

        self::assertSame(['Decyzje'], $this->titles());
    }

    public function testFindsADottedTechnicalName(): void
    {
        $this->write('schemat', 'Schemat', 'Migracja zakłada tabelę ws.memory_entries z kluczem obcym.');

        $this->search('ws.memory_entries');

        self::assertSame(['Schemat'], $this->titles());
    }

    public function testSeveralWordsNarrowTheResultInsteadOfWideningIt(): void
    {
        $this->write('a', 'Nocleg i akceptacja', 'Nocleg powyżej progu wymaga akceptacji przełożonego.');
        $this->write('b', 'Sam nocleg', 'Nocleg do trzystu złotych bez formalności.');

        $this->search('nocleg akceptacji');

        self::assertSame(['Nocleg i akceptacja'], $this->titles(), 'drugie słowo ma zawężać, nie dokładać');
    }

    // ------------------------------------------------------------ permissions

    /**
     * The rule that must never bend: a search reaches only into spaces the
     * searcher may read. Not "results are filtered afterwards" — the query does
     * not go there at all (inviolable rule 3).
     */
    public function testASearchNeverReachesIntoASpaceTheUserCannotRead(): void
    {
        $this->write('tajne', 'Tajny dokument', 'Wyjątkowe słowo szyfrogram w treści.', space: 'kadry');

        $this->client->request(
            'GET',
            '/api/search?' . http_build_query(['q' => 'szyfrogram', 'mode' => 'lexical']),
            server: $this->authAs('obcy@web-systems.pl'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count'], 'obcy nie widzi treści z przestrzeni, do której nie należy');
    }

    public function testNarrowingToASpaceCannotWidenTheSearch(): void
    {
        $this->write('tajne', 'Tajny dokument', 'Wyjątkowe słowo szyfrogram w treści.', space: 'kadry');

        // The stranger asks explicitly for a space that is not theirs: naming it
        // must not grant it.
        $this->client->request(
            'GET',
            '/api/search?' . http_build_query(['q' => 'szyfrogram', 'mode' => 'lexical', 'spaces' => ['kadry']]),
            server: $this->authAs('obcy@web-systems.pl'),
        );

        self::assertSame(0, $this->json()['count']);
    }

    public function testNarrowingToASpaceExcludesTheOthers(): void
    {
        $this->write('jeden', 'W wiedzy', 'Wspólne słowo rozpoznawalne tutaj.');
        $this->write('dwa', 'W kadrach', 'Wspólne słowo rozpoznawalne również tutaj.', space: 'kadry');

        $this->client->request(
            'GET',
            '/api/search?' . http_build_query(['q' => 'rozpoznawalne', 'mode' => 'lexical', 'spaces' => ['kadry']]),
            server: $this->authAs('pisarz@web-systems.pl'),
        );

        self::assertSame(['W kadrach'], $this->titles());
    }

    public function testAnArchivedDocumentDropsOutOfSearch(): void
    {
        $this->write('stare', 'Stary dokument', 'Zawiera słowo przedawnione.');

        $this->client->request(
            'POST',
            '/api/spaces/wiedza/documents/stare/archive',
            server: $this->authAs('pisarz@web-systems.pl'),
        );
        self::assertResponseIsSuccessful();

        $this->search('przedawnione');

        self::assertSame(0, $this->json()['count'], 'zarchiwizowany dokument nie jest odpowiedzią');
    }

    // ------------------------------------------------------------- the answer

    /**
     * Lexical mode cannot see inside anything but documents (D-029), and the
     * answer says so. A client that hid this would be promising a search of the
     * whole base while delivering a search of part of it.
     */
    public function testTheAnswerStatesWhatTheModeCouldNotLookInside(): void
    {
        $this->search('cokolwiek');

        self::assertFalse($this->json()['coverage']['fullText']);
        self::assertStringContainsString('dokument', $this->json()['coverage']['note']);
    }

    public function testTheAnswerEchoesTheQueryItActuallyRan(): void
    {
        $this->search('zapytanie testowe');

        self::assertSame('zapytanie testowe', $this->json()['query']);
        self::assertSame('lexical', $this->json()['mode']);
    }

    public function testNoMatchesIsAnEmptyAnswerRatherThanAnError(): void
    {
        $this->write('cokolwiek', 'Cokolwiek', 'Zupełnie inna treść.');

        $this->search('niewystępującesłowo');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);
        self::assertSame([], $this->json()['results']);
    }

    public function testAQueryOfPurePunctuationAnswersEmpty(): void
    {
        $this->write('cokolwiek', 'Cokolwiek', 'Jakaś treść.');

        $this->search('???');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);
    }

    // -------------------------------------------------------------- rejection

    public function testAnEmptyQueryIsRejectedWithAReadableMessage(): void
    {
        $this->search('');

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $this->json());
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $this->client->request(
            'GET',
            '/api/search?' . http_build_query(['q' => 'cokolwiek', 'mode' => 'magiczny']),
            server: $this->authAs('pisarz@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->client->request(
            'GET',
            '/api/search?' . http_build_query(['q' => 'cokolwiek', 'mode' => 'lexical', 'kind' => 'wymyslona']),
            server: $this->authAs('pisarz@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testSearchingWithoutSigningInIsRefused(): void
    {
        $this->client->request('GET', '/api/search?q=cokolwiek');

        self::assertResponseStatusCodeSame(401);
    }

    // ---------------------------------------------------------------- helpers

    private function write(string $slug, string $title, string $content, string $space = 'wiedza'): void
    {
        $this->client->request(
            'PUT',
            \sprintf('/api/spaces/%s/documents/%s', $space, $slug),
            server: $this->authAs('pisarz@web-systems.pl'),
            content: json_encode(['title' => $title, 'content' => $content], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    private function search(string $query, string $mode = 'lexical'): void
    {
        $this->client->request(
            'GET',
            '/api/search?' . http_build_query(['q' => $query, 'mode' => $mode]),
            server: $this->authAs('pisarz@web-systems.pl'),
        );
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_values(array_map(
            static fn (array $result): string => (string) $result['title'],
            $this->json()['results'],
        ));
    }

    /** @return list<string> the words the answer marked as matching */
    private function matchedWords(): array
    {
        $words = [];
        foreach ($this->json()['results'] as $result) {
            foreach ($result['snippet'] as $part) {
                if (true === $part['match']) {
                    $words[] = (string) $part['text'];
                }
            }
        }

        return $words;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'odpowiedź nie jest JSON-em: ' . $content);

        return $decoded;
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
}
