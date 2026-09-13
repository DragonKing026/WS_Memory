<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The instruction texts as an MCP client meets them: `resources/list` and
 * `resources/read` over the gateway.
 *
 * Why the gateway serves them at all: the content has one source in
 * `plugin/shared/` and lives on the server, so changing an instruction is a
 * deployment instead of an update everybody has to install, and a client that is
 * not Claude Code reads exactly the same text (D-013).
 *
 * Two tests here are the ones worth keeping. The first compares what comes out of
 * `resources/read` with the actual file in `plugin/shared/` — the completion
 * criterion of TODO-009 — rather than with a copy pasted into this file, which
 * would keep passing on the day the instruction changes. The second counts rows in
 * the audit log: reading a resource must not write one.
 *
 * Deliberately does NOT need a running palace. Instructions are files; nothing
 * here goes near memory.
 *
 * Each test stays under five calls per token, the rate limit in the test
 * environment (phpunit.dist.xml).
 */
final class McpResourcesTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    /** Every URI the gateway publishes. Its contents are a contract with clients. */
    private const PUBLISHED = [
        'ws-memory://protokol-recall',
        'ws-memory://jak-dokumentowac',
        'ws-memory://konfiguracja',
        'ws-memory://agenci/ws-recall',
        'ws-memory://agenci/ws-dokumentalista',
        'ws-memory://agenci/ws-archiwista',
        'ws-memory://agenci/ws-onboarding',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $owner;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();

        $this->connection->executeStatement(
            'TRUNCATE ws.memory_entries, ws.agent_tokens, ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $issueInvitation = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);
        $this->owner = ($accept)(($issueInvitation)('wlasciciel@web-systems.pl')->plainToken, 'Właściciel', self::PASSWORD);

        $this->token = ($container->get(IssueAgentToken::class))($this->owner, 'laptop')->plainToken;
    }

    public function testInitializeDeclaresResourcesAmongItsCapabilities(): void
    {
        // A client that is not told about resources never asks for them, so the
        // instructions would be published and unread.
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18']);

        // Asserted on the raw body: decoded, an empty JSON object and an empty
        // array are the same PHP value, and only one of the two is valid here.
        self::assertStringContainsString(
            '"resources":{}',
            (string) $this->client->getResponse()->getContent(),
            'capabilities.resources musi być obiektem, nie tablicą',
        );
    }

    public function testResourcesListNamesEveryPublishedInstruction(): void
    {
        $resources = $this->rpc('resources/list')['result']['resources'];

        self::assertSame(self::PUBLISHED, array_column($resources, 'uri'));

        foreach ($resources as $resource) {
            foreach (['name', 'title', 'description'] as $field) {
                self::assertNotSame('', trim((string) $resource[$field]), $resource['uri'] . " bez pola $field");
            }

            // Declared, so a client renders the document instead of showing an
            // agent a wall of asterisks.
            self::assertSame('text/markdown', $resource['mimeType']);
        }
    }

    public function testResourceReadReturnsTheBodyOfTheFileInPluginShared(): void
    {
        $answer = $this->rpc('resources/read', ['uri' => 'ws-memory://protokol-recall']);
        $contents = $answer['result']['contents'];

        self::assertCount(1, $contents);
        self::assertSame('ws-memory://protokol-recall', $contents[0]['uri']);
        self::assertSame('text/markdown', $contents[0]['mimeType']);

        $raw = $this->fileInPluginShared('protokol-recall.md');

        // The tail of the real file, byte for byte. Compared with the file rather
        // than with a copy pasted in here, because a copy would stop meaning
        // anything the moment somebody edits the instruction.
        self::assertStringEndsWith($contents[0]['text'], $raw);
        self::assertStringContainsString('ws_search', $contents[0]['text'], 'to nie jest treść protokołu recall');
    }

    public function testFrontMatterIsNotPartOfTheResource(): void
    {
        $text = $this->rpc('resources/read', ['uri' => 'ws-memory://jak-dokumentowac'])['result']['contents'][0]['text'];

        self::assertStringStartsWith(
            "---\n",
            $this->fileInPluginShared('jak-dokumentowac.md'),
            'plik źródłowy ma frontmatter — bez niego ten test nic nie sprawdza',
        );

        // Packaging metadata for a plugin loader. Passed on, it would spend the
        // agent's context on fields that say nothing to a model.
        self::assertStringStartsNotWith('---', $text);
        self::assertStringNotContainsString('description:', $text);
        self::assertStringNotContainsString('noteId:', $text);
    }

    public function testUnknownUriIsAJsonRpcErrorRatherThanAnEmptyDocument(): void
    {
        // D-023: a failure is an error, never a successful result carrying an
        // error field. An empty document would read to an agent as an instruction
        // saying nothing.
        $answer = $this->rpc('resources/read', ['uri' => 'ws-memory://czego-nie-ma']);

        self::assertArrayNotHasKey('result', $answer);
        self::assertSame(-32002, $answer['error']['code']);
        self::assertStringContainsString('resources/list', $answer['error']['message']);
    }

    public function testReadWithoutAUriIsRefused(): void
    {
        self::assertSame(-32602, $this->rpc('resources/read')['error']['code']);
    }

    public function testReadingAResourceLeavesNoAuditEntry(): void
    {
        $before = $this->auditRows();

        $this->rpc('resources/list');
        $this->rpc('resources/read', ['uri' => 'ws-memory://protokol-recall']);

        self::assertSame(
            $before,
            $this->auditRows(),
            'odczyt zasobu to statyczny tekst, identyczny dla każdego tokena — audytowanie go zalewa dziennik',
        );

        // The contrast, in one test: a tool call is a real action and does leave a
        // row. What is switched off here is the audit of a request, not the audit.
        $this->rpcTool('ws_status');

        self::assertSame($before + 1, $this->auditRows());
    }

    // ------------------------------------------------------------------ setup

    private function fileInPluginShared(string $relative): string
    {
        // Through the configured directory, which is the one the application
        // reads: it differs between a host run and a container, and hard-coding
        // either would make this test lie in the other.
        $directory = (string) static::getContainer()->getParameter('app.instructions_dir');
        $path = rtrim($directory, '/') . '/' . $relative;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function auditRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM ws.audit_log');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the whole JSON-RPC answer, error included
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function rpcTool(string $name): array
    {
        $this->post([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => []],
        ]);

        return $this->json();
    }

    private function post(mixed $body): void
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
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
