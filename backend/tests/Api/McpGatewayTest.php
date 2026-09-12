<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\AgentToken\RevokeAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceRole;
use App\Entity\AgentToken;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The gateway as an agent meets it: the protocol, and what it refuses.
 *
 * Deliberately does NOT need a running palace. Everything asserted here happens
 * before memory is reached — an empty intersection of permissions never asks it,
 * a refused write never gets that far, and `tools/list` has nothing to ask about.
 * That is what lets these run on every commit; the round trip through a real
 * palace lives in tests/Integration.
 *
 * The tests that matter most are the ones about silence. A foreign space must come
 * back as an empty result rather than an error, because "you have no access to HR"
 * tells the caller that an HR space exists (inviolable rule 7) — and a scope that
 * fails to narrow would not throw anything at all, it would simply return too much.
 */
final class McpGatewayTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $owner;
    private string $fullToken;
    private string $narrowedToken;

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

        foreach (['alfa' => SpaceRole::Writer, 'beta' => SpaceRole::Reader] as $slug => $role) {
            $space = new Space($slug, ucfirst($slug), 'wing_' . $slug);
            $this->em->persist($space);
            $this->em->persist(new SpaceMember($space, $this->owner, $role));
        }

        // A space the owner is not a member of. Nothing they hold may ever see it.
        $this->em->persist(new Space('kadry', 'Kadry', 'wing_kadry'));
        $this->em->flush();

        $issueToken = $container->get(IssueAgentToken::class);
        $this->fullToken = ($issueToken)($this->owner, 'laptop')->plainToken;
        $this->narrowedToken = ($issueToken)($this->owner, 'ci', ['alfa'])->plainToken;
    }

    // --------------------------------------------------------------- protocol

    public function testInitializeAnswersWithCapabilitiesAndServerInfo(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => '2025-06-18'])['result'];

        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertSame('ws_memory', $result['serverInfo']['name']);

        // Asserted on the raw body: decoded, an empty JSON object and an empty
        // array are the same PHP value, and only one of the two is a valid
        // handshake.
        self::assertStringContainsString(
            '"tools":{}',
            (string) $this->client->getResponse()->getContent(),
            'capabilities.tools musi być obiektem, nie tablicą',
        );
    }

    public function testUnknownProtocolVersionGetsOursRatherThanARefusal(): void
    {
        // Refusing an unknown revision would lock out clients that would work
        // perfectly well — the tool schemas do not differ between revisions.
        $result = $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])['result'];

        self::assertSame('2025-06-18', $result['protocolVersion']);
    }

    public function testToolsListReturnsTheCuratedSet(): void
    {
        $names = array_column($this->rpc('tools/list')['result']['tools'], 'name');

        self::assertSame([
            'ws_diary_write',
            'ws_doc_list',
            'ws_doc_read',
            'ws_doc_write',
            'ws_get',
            'ws_kg_add',
            'ws_kg_query',
            'ws_propose',
            'ws_remember',
            'ws_search',
            'ws_status',
        ], $names, 'the tool set is the permission boundary — its contents are a contract');

        // Named absences, each with a reason in docs/03: an agent does not confirm
        // its own entries (D-005), documents are archived rather than deleted, and
        // administration is a human act.
        foreach (['ws_doc_verify', 'ws_doc_delete', 'ws_space_create', 'ws_token_issue'] as $refused) {
            self::assertNotContains($refused, $names);
        }
    }

    public function testNoToolLetsTheCallerNameItsAuthor(): void
    {
        // Impersonation has to be inexpressible rather than forbidden (rule 2).
        // This walks every schema so that adding a tool cannot quietly break it.
        $forbidden = ['author', 'autor', 'added_by', 'user', 'user_id', 'actor', 'on_behalf_of', 'token'];

        foreach ($this->rpc('tools/list')['result']['tools'] as $tool) {
            $properties = array_keys($tool['inputSchema']['properties'] ?? []);

            foreach ($properties as $property) {
                self::assertNotContains(
                    $property,
                    $forbidden,
                    \sprintf('narzędzie %s wystawia pole autora: %s', $tool['name'], $property),
                );
            }

            // A wing is the palace's vocabulary and must never be an argument:
            // the whole permission model rests on the server choosing it.
            self::assertNotContains('wing', $properties, $tool['name'] . ' pozwala wskazać skrzydło pałaca');
        }
    }

    public function testSchemaOfAToolWithoutArgumentsIsStillAnObject(): void
    {
        $this->rpc('tools/list');

        self::assertStringNotContainsString(
            '"properties":[]',
            (string) $this->client->getResponse()->getContent(),
            'puste „properties" musi być obiektem — inaczej to nie jest poprawny JSON Schema',
        );
    }

    public function testEveryToolDeclaresItsSchemaStrictly(): void
    {
        foreach ($this->rpc('tools/list')['result']['tools'] as $tool) {
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertFalse(
                $tool['inputSchema']['additionalProperties'] ?? true,
                $tool['name'] . ' przyjmuje nieznane parametry — pominięty parametr to cichy błąd agenta',
            );
            self::assertNotSame('', trim($tool['description']), $tool['name'] . ' nie ma opisu');
        }
    }

    public function testUnknownMethodIsRefusedWithTheProtocolCode(): void
    {
        self::assertSame(-32601, $this->rpc('nie/istnieje')['error']['code']);
    }

    public function testNotificationGetsAcceptedWithoutABody(): void
    {
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->fullToken);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testBatchRequestIsRefused(): void
    {
        $this->post([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], $this->fullToken);

        self::assertSame(-32600, $this->json()['error']['code']);
    }

    public function testMalformedJsonIsRefused(): void
    {
        $this->client->request('POST', '/mcp', server: $this->headers($this->fullToken), content: '{to nie jest json');

        self::assertSame(-32700, $this->json()['error']['code']);
    }

    // --------------------------------------------------------- authentication

    public function testNoTokenIsUnauthorised(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHasHeader('WWW-Authenticate');
    }

    public function testUnknownTokenIsUnauthorised(): void
    {
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], 'wsm_nie-ma-takiego-tokena');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedTokenIsRefusedOnTheVeryNextCall(): void
    {
        $container = static::getContainer();
        $issued = ($container->get(IssueAgentToken::class))($this->owner, 'do unieważnienia');

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $issued->plainToken);
        self::assertResponseIsSuccessful();

        ($container->get(RevokeAgentToken::class))($this->owner, $issued->tokenId);

        $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], $issued->plainToken);
        self::assertResponseStatusCodeSame(401, 'unieważnienie musi działać natychmiast, nie po wygaśnięciu czegokolwiek');
    }

    public function testExpiredTokenIsRefused(): void
    {
        // Built through the entity rather than the use case, which refuses a past
        // expiry on purpose. What is under test here is the SQL condition that
        // decides whether an already-expired token resolves at all.
        $plain = 'wsm_' . bin2hex(random_bytes(32));
        $this->em->persist(new AgentToken(
            owner: $this->owner,
            label: 'wygasły',
            tokenHash: hash('sha256', $plain),
            spaceScope: null,
            expiresAt: new \DateTimeImmutable('-1 hour'),
        ));
        $this->em->flush();

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $plain);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeactivatedOwnerTakesTheirAgentsWithThem(): void
    {
        $this->owner->deactivate();
        $this->em->flush();

        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $this->fullToken);

        self::assertResponseStatusCodeSame(
            401,
            'wyłączone konto musi odciąć jego agentów bez unieważniania tokenów po kolei',
        );
    }

    // ------------------------------------------------------------ permissions

    public function testStatusListsTheSpacesTheTokenMayReachAndNothingElse(): void
    {
        $payload = $this->callTool('ws_status', [], $this->fullToken);
        $slugs = array_column($payload['spaces'], 'slug');

        self::assertContains('alfa', $slugs);
        self::assertContains('beta', $slugs);
        self::assertNotContains('kadry', $slugs, 'przestrzeń bez członkostwa nie może się tu pojawić');

        // The private space belongs here too: an agent has to know where a write
        // with no space named will land, and that is the space.
        self::assertCount(3, $slugs);
        self::assertFalse($payload['scoped']);

        $roles = array_column($payload['spaces'], 'role', 'slug');
        self::assertSame('writer', $roles['alfa']);
        self::assertSame('reader', $roles['beta']);
    }

    public function testNarrowedTokenSeesLessThanItsOwner(): void
    {
        $payload = $this->callTool('ws_status', [], $this->narrowedToken);

        self::assertSame(['alfa'], array_column($payload['spaces'], 'slug'));
        self::assertTrue($payload['scoped']);
        self::assertNull(
            $payload['default_write_space'],
            'token zawężony do przestrzeni zespołowej nie ma gdzie pisać domyślnie — i tak ma być',
        );
    }

    public function testStatusNamesWhereAnUnaddressedWriteWillLand(): void
    {
        $payload = $this->callTool('ws_status', [], $this->fullToken);

        self::assertStringStartsWith('priv_', (string) $payload['default_write_space']);
    }

    public function testSearchingAForeignSpaceIsEmptyRatherThanAnError(): void
    {
        // The heart of this file. An error would confirm the space exists; content
        // would be a leak. An empty result is the only answer that reveals nothing
        // — and note that the palace is never even asked, so this passes with the
        // sidecar switched off.
        $answer = $this->rpcTool('ws_search', ['query' => 'cokolwiek', 'spaces' => ['kadry']], $this->fullToken);

        self::assertArrayNotHasKey('error', $answer, 'brak uprawnień nie może być błędem');

        $payload = $this->payloadOf($answer);
        self::assertSame([], $payload['results']);
        self::assertSame(0, $payload['count']);
    }

    public function testNarrowedTokenSearchingOutsideItsScopeIsAlsoEmpty(): void
    {
        $answer = $this->rpcTool('ws_search', ['query' => 'cokolwiek', 'spaces' => ['beta']], $this->narrowedToken);

        self::assertSame([], $this->payloadOf($answer)['results'], 'zakres tokena może tylko zawężać');
    }

    public function testWritingToASpaceWithoutTheWriterRoleIsRefused(): void
    {
        // Refused, not silently redirected: the agent named the space itself, so
        // it already knows the space exists and needs to be told to stop.
        $answer = $this->rpcTool('ws_remember', ['text' => 'treść', 'space' => 'beta'], $this->fullToken);

        self::assertSame(-32003, $answer['error']['code']);
    }

    public function testWritingToAForeignSpaceIsRefusedTheSameWay(): void
    {
        $answer = $this->rpcTool('ws_remember', ['text' => 'treść', 'space' => 'kadry'], $this->fullToken);

        self::assertSame(-32003, $answer['error']['code'], 'obca i tylko-do-czytania przestrzeń odmawiają identycznie');
    }

    // --------------------------------------------------------------- arguments

    public function testUnknownArgumentIsRefusedRatherThanIgnored(): void
    {
        // `wing` is the argument an agent is most likely to invent, having learned
        // it from the local MemPalace server. Ignoring it would let the agent
        // believe it had filtered its search when it had not.
        $answer = $this->rpcTool('ws_search', ['query' => 'x', 'wing' => 'wing_kadry'], $this->fullToken);

        self::assertSame(-32602, $answer['error']['code']);
        self::assertStringContainsString('wing', $answer['error']['message']);
    }

    public function testMissingRequiredArgumentIsRefused(): void
    {
        self::assertSame(-32602, $this->rpcTool('ws_search', [], $this->fullToken)['error']['code']);
    }

    public function testWrongArgumentTypeIsRefused(): void
    {
        $answer = $this->rpcTool('ws_search', ['query' => 'x', 'limit' => '5'], $this->fullToken);

        self::assertSame(-32602, $answer['error']['code']);
    }

    public function testUnknownToolIsRefused(): void
    {
        self::assertSame(-32601, $this->rpcTool('ws_wszystko', [], $this->fullToken)['error']['code']);
    }

    // ------------------------------------------------------------------- audit

    public function testEveryToolCallLeavesAnAuditEntry(): void
    {
        $this->callTool('ws_status', [], $this->fullToken);

        $row = $this->connection->fetchAssociative(
            "SELECT action, actor_user_id, actor_agent_token_id FROM ws.audit_log WHERE action = 'mcp.ws_status'"
        );

        self::assertIsArray($row);
        self::assertSame($this->owner->getId()->toRfc4122(), $row['actor_user_id']);
        self::assertNotNull($row['actor_agent_token_id'], 'wpis musi wskazywać token, nie tylko właściciela');
    }

    public function testAFailedCallIsAuditedToo(): void
    {
        // The calls worth investigating are the ones that failed. Auditing only
        // successes would make exactly those invisible.
        $this->rpcTool('ws_remember', ['text' => 'treść', 'space' => 'kadry'], $this->fullToken);

        $row = $this->connection->fetchAssociative(
            "SELECT target FROM ws.audit_log WHERE action = 'mcp.ws_remember'"
        );

        self::assertIsArray($row);
        self::assertStringContainsString('MemoryAccessDenied', (string) $row['target']);
    }

    public function testTokenUsageIsRecorded(): void
    {
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $this->fullToken);

        $row = $this->connection->fetchAssociative(
            "SELECT last_used_at, last_used_ip FROM ws.agent_tokens WHERE label = 'laptop'"
        );

        self::assertIsArray($row);
        self::assertNotNull($row['last_used_at'], 'bez tego nikt nie odważy się wycofać martwego tokena');
    }

    // -------------------------------------------------------------- rate limit

    public function testTooManyCallsFromOneTokenAreRefused(): void
    {
        // The limit is five in the test environment (phpunit.dist.xml).
        for ($i = 1; $i <= 5; ++$i) {
            $this->post(['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'], $this->fullToken);
            self::assertResponseIsSuccessful(\sprintf('wywołanie %d powinno przejść', $i));
        }

        $this->post(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping'], $this->fullToken);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(-32005, $this->json()['error']['code']);
    }

    public function testTheLimitIsPerTokenAndNotPerAccount(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->post(['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'], $this->fullToken);
        }
        self::assertResponseStatusCodeSame(429);

        // The owner's other token is untouched: one runaway loop must not stop
        // everything that person has running.
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $this->narrowedToken);
        self::assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------ setup

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $this->fullToken);

        return $this->json();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the whole JSON-RPC answer, error included
     */
    private function rpcTool(string $name, array $arguments, string $token): array
    {
        $this->post([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $token);

        return $this->json();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the tool payload, asserting the call succeeded
     */
    private function callTool(string $name, array $arguments, string $token): array
    {
        $answer = $this->rpcTool($name, $arguments, $token);

        self::assertArrayNotHasKey('error', $answer, (string) json_encode($answer['error'] ?? null, \JSON_UNESCAPED_UNICODE));

        return $this->payloadOf($answer);
    }

    /**
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    private function payloadOf(array $answer): array
    {
        $text = $answer['result']['content'][0]['text'] ?? '';
        self::assertIsString($text);

        $payload = json_decode($text, true);
        self::assertIsArray($payload, 'treść narzędzia musi być JSON-em w części tekstowej');

        return $payload;
    }

    private function post(mixed $body, string $token): void
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: $this->headers($token),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
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
