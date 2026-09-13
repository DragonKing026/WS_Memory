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
use Symfony\Component\Uid\Uuid;

/**
 * Browsing raw memory.
 *
 * Rows are written straight into the registry rather than through the palace: this
 * endpoint reads our own table and nothing else, which is the point of it, and a test
 * that needed a live palace would not run on every commit.
 *
 * What matters here is the same rule as everywhere on this boundary — the listing
 * shows what the reader may read, and naming somebody else's space does not grant it.
 */
final class MemoryBrowseTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

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

        $member = ($accept)(($issue)('czlonek@web-systems.pl')->plainToken, 'Członek', self::PASSWORD);
        ($accept)(($issue)('obcy@web-systems.pl')->plainToken, 'Obcy', self::PASSWORD);

        $wiedza = new Space('wiedza', 'Wiedza', 'wing_wiedza');
        $kadry = new Space('kadry', 'Kadry', 'wing_kadry');
        $this->em->persist($wiedza);
        $this->em->persist($kadry);
        $this->em->persist(new SpaceMember($wiedza, $member, SpaceRole::Reader));
        $this->em->flush();

        $this->fileEntry('drawer_wiedza_1', 'wiedza', 'note', 'Notatka w wiedzy', $member, byAgent: false);
        $this->fileEntry('drawer_wiedza_2', 'wiedza', 'diary', 'Wpis dziennika', $member, byAgent: true);
        $this->fileEntry('drawer_kadry_1', 'kadry', 'note', 'Notatka w kadrach', $member, byAgent: false);
    }

    public function testShowsOnlyEntriesFromSpacesTheReaderMayRead(): void
    {
        $this->browse();

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Wpis dziennika', 'Notatka w wiedzy'],
            $this->titles(),
            'kadry są poza uprawnieniami tej osoby',
        );
    }

    public function testAStrangerSeesNothingRatherThanAnError(): void
    {
        $this->client->request('GET', '/api/memory', server: $this->authAs('obcy@web-systems.pl'));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);
    }

    /** Naming a space you may not read must not grant it (inviolable rule 3). */
    public function testNamingAForbiddenSpaceDoesNotGrantIt(): void
    {
        $this->client->request(
            'GET',
            '/api/memory?' . http_build_query(['spaces' => ['kadry']]),
            server: $this->authAs('czlonek@web-systems.pl'),
        );

        self::assertSame(0, $this->json()['count'], 'wskazanie może tylko zawężać');
    }

    public function testFilteringByKindNarrowsTheListing(): void
    {
        $this->client->request(
            'GET',
            '/api/memory?' . http_build_query(['kind' => 'diary']),
            server: $this->authAs('czlonek@web-systems.pl'),
        );

        self::assertSame(['Wpis dziennika'], $this->titles());
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->client->request(
            'GET',
            '/api/memory?' . http_build_query(['kind' => 'wymyslona']),
            server: $this->authAs('czlonek@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(400);
    }

    /** An entry written by an agent must be distinguishable from one written by a person. */
    public function testTheListingSaysWhoWroteEachEntry(): void
    {
        $this->browse();

        $byTitle = [];
        foreach ($this->json()['entries'] as $entry) {
            $byTitle[$entry['title']] = $entry['byAi'];
        }

        self::assertTrue($byTitle['Wpis dziennika']);
        self::assertFalse($byTitle['Notatka w wiedzy']);
    }

    public function testTheListingIsPagedAndSaysWhenThereIsMore(): void
    {
        $this->client->request(
            'GET',
            '/api/memory?' . http_build_query(['limit' => 1]),
            server: $this->authAs('czlonek@web-systems.pl'),
        );

        self::assertSame(1, $this->json()['count']);
        self::assertTrue($this->json()['hasMore']);
    }

    public function testBrowsingWithoutSigningInIsRefused(): void
    {
        $this->client->request('GET', '/api/memory');

        self::assertResponseStatusCodeSame(401);
    }

    // ---------------------------------------------------------------- helpers

    private function fileEntry(
        string $drawer,
        string $spaceSlug,
        string $kind,
        string $title,
        User $author,
        bool $byAgent,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ws.memory_entries (
                    id, drawer_id, space_id, kind, author_user_id, author_agent_token_id,
                    title, tags, content_hash, created_at
                )
                SELECT :id, :drawer, s.id, :kind, :author, :token, :title, CAST('[]' AS JSONB),
                       :hash, :createdAt
                FROM ws.spaces s WHERE s.slug = :slug
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'drawer' => $drawer,
                'kind' => $kind,
                'author' => $author->getId()->toRfc4122(),
                // A token id is what marks a row as written by an agent; the column is
                // nullable and its emptiness is the "a person wrote this" answer.
                'token' => $byAgent ? Uuid::v7()->toRfc4122() : null,
                'title' => $title,
                'hash' => str_repeat('a', 64),
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'slug' => $spaceSlug,
            ],
        );
    }

    private function browse(): void
    {
        $this->client->request('GET', '/api/memory', server: $this->authAs('czlonek@web-systems.pl'));
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_values(array_map(
            static fn (array $entry): string => (string) $entry['title'],
            $this->json()['entries'],
        ));
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
