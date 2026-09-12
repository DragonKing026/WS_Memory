<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use App\Infrastructure\MemPalace\MemPalaceClient;
use App\Infrastructure\MemPalace\MemPalaceHealthProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What an agent actually experiences: token, JSON-RPC, a real palace.
 *
 * McpGatewayTest proves the protocol and the refusals without a palace, which is
 * what makes it cheap enough to run on every commit. This file proves the part that
 * cannot be faked — that a write through `ws_remember` is findable through
 * `ws_search` afterwards, in Polish, by different words, and only by a token that
 * may see that space.
 *
 * Skipped when the palace does not answer; the nightly run stands the full stack up
 * and this runs there (docs/09-ci.md).
 */
#[Group('integracja')]
final class McpOnLivePalaceTest extends WebTestCase
{
    use RequiresLivePalace;

    private const CONTENT = 'Faktury kosztowe księgujemy w miesiącu wykonania usługi, nie w miesiącu wpływu';
    private const OTHER_WORDS = 'do jakiego okresu trafia rachunek od podwykonawcy';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;
    private string $strangerToken;
    private string $wing;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $palace = $container->get(MemPalaceClient::class);
        self::assertInstanceOf(MemPalaceClient::class, $palace);

        $zdrowie = $container->get(MemPalaceHealthProbe::class);
        self::assertInstanceOf(MemPalaceHealthProbe::class, $zdrowie);
        self::skipUnlessPalaceAnswers($zdrowie);

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.memory_entries, ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        // A fresh wing per run: a shared one fills up with content that is
        // semantically close to this run's, and the palace applies its own limit
        // before we see anything — eventually a run would rank itself out.
        $this->wing = 'test-mcp-' . bin2hex(random_bytes(4));

        $issueInvitation = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        $member = ($accept)(($issueInvitation)('ksiegowa@web-systems.pl')->plainToken, 'Księgowa', 'DlugieHaslo123!x');
        $stranger = ($accept)(($issueInvitation)('obcy@web-systems.pl')->plainToken, 'Obcy', 'DlugieHaslo123!x');

        $space = new Space('finanse', 'Finanse', $this->wing);
        $this->em->persist($space);
        $this->em->persist(new SpaceMember($space, $member, SpaceRole::Writer));
        $this->em->flush();

        $issueToken = $container->get(IssueAgentToken::class);
        $this->token = ($issueToken)($member, 'test integracyjny')->plainToken;
        $this->strangerToken = ($issueToken)($stranger, 'obcy agent')->plainToken;
    }

    public function testWhatAnAgentWritesItCanFindAgainByDifferentWords(): void
    {
        // The whole product in one test: a write through MCP, then a Polish query
        // sharing no words with it, and the content comes back.
        $written = $this->tool('ws_remember', ['text' => self::CONTENT, 'space' => 'finanse'], $this->token);
        self::assertArrayHasKey('id', $written);

        $found = $this->tool('ws_search', ['query' => self::OTHER_WORDS, 'limit' => 20], $this->token);

        self::assertContains(
            $written['id'],
            array_column($found['results'], 'id'),
            'zapytanie po polsku innymi słowami musi znaleźć to, co agent zapisał',
        );
    }

    public function testFullContentComesBackThroughGet(): void
    {
        $written = $this->tool('ws_remember', ['text' => self::CONTENT, 'space' => 'finanse'], $this->token);

        $fetched = $this->tool('ws_get', ['id' => $written['id']], $this->token);

        self::assertTrue($fetched['found']);
        self::assertSame(self::CONTENT, $fetched['content']);
        self::assertSame('finanse', $fetched['space']);
    }

    public function testAnotherOwnersAgentFindsNothingAndFetchesNothing(): void
    {
        $written = $this->tool('ws_remember', ['text' => self::CONTENT, 'space' => 'finanse'], $this->token);

        $found = $this->tool('ws_search', ['query' => self::OTHER_WORDS, 'limit' => 20], $this->strangerToken);
        self::assertSame([], $found['results']);

        // Identical to an identifier that was never issued — no hint that the
        // content exists somewhere else.
        $fetched = $this->tool('ws_get', ['id' => $written['id']], $this->strangerToken);
        self::assertFalse($fetched['found']);
    }

    public function testWriteWithNoSpaceLandsInThePrivateSpaceAndIsReadableThere(): void
    {
        $status = $this->tool('ws_status', [], $this->token);
        $private = $status['default_write_space'];
        self::assertIsString($private);

        $written = $this->tool('ws_remember', ['text' => 'Notatka robocza bez wskazanej przestrzeni'], $this->token);
        $fetched = $this->tool('ws_get', ['id' => $written['id']], $this->token);

        self::assertSame($private, $fetched['space'], 'zapis bez przestrzeni ląduje w prywatnej właściciela');
    }

    public function testStatusCountsGrowWithWhatWasWritten(): void
    {
        $before = $this->entriesIn($this->tool('ws_status', [], $this->token), 'finanse');

        $this->tool('ws_remember', ['text' => self::CONTENT, 'space' => 'finanse'], $this->token);

        $after = $this->entriesIn($this->tool('ws_status', [], $this->token), 'finanse');

        self::assertSame($before + 1, $after, 'liczby w ws_status idą z naszego rejestru, nie z pałaca');
    }

    public function testFactWrittenThroughMcpComesBackThroughMcp(): void
    {
        $this->tool('ws_kg_add', [
            'subject' => 'faktura kosztowa',
            'predicate' => 'ksiegowana_w',
            'object' => 'miesiacu wykonania uslugi',
            'space' => 'finanse',
        ], $this->token);

        $facts = $this->tool('ws_kg_query', ['entity' => 'faktura kosztowa'], $this->token);

        self::assertSame(1, $facts['count']);
        self::assertSame('miesiacu wykonania uslugi', $facts['facts'][0]['object']);

        // The scope lives in the entity key (D-021), so another owner's agent does
        // not match the fact rather than matching and being filtered.
        self::assertSame(0, $this->tool('ws_kg_query', ['entity' => 'faktura kosztowa'], $this->strangerToken)['count']);
    }

    public function testDiaryEntryGoesThroughAndIsReadableBack(): void
    {
        $written = $this->tool('ws_diary_write', [
            'text' => 'SESSION:2026-09-12|TODO-004|gateway MCP działa',
            'topic' => 'todo-004',
        ], $this->token);

        $fetched = $this->tool('ws_get', ['id' => $written['id']], $this->token);

        self::assertTrue($fetched['found']);
        self::assertSame('diary', $fetched['kind_room']);
    }

    /**
     * @param array<string, mixed> $status
     */
    private function entriesIn(array $status, string $slug): int
    {
        foreach ($status['spaces'] as $space) {
            if ($slug === $space['slug']) {
                return (int) $space['entries'];
            }
        }

        self::fail(\sprintf('ws_status nie wymienia przestrzeni %s', $slug));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments, string $token): array
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
}
