<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemPalace;

use App\Infrastructure\MemPalace\MemPalaceClient;
use App\Infrastructure\MemPalace\MemPalaceUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The wire between us and the palace.
 *
 * The case that justifies this file is the third test: MemPalace answers HTTP
 * 200 with a well-formed JSON-RPC envelope and puts the tool's failure inside
 * the payload, next to an empty result list. A client that trusts the envelope
 * turns an outage into "nothing found" — and an agent told "nothing found"
 * writes the knowledge again instead of reading it.
 */
final class MemPalaceClientTest extends TestCase
{
    private const TOKEN = 'sekretny-token-do-palaca';

    public function testSuccessfulCallReturnsTheToolPayload(): void
    {
        $client = $this->clientAnswering([
            $this->envelope(['results' => [['drawer_id' => 'drawer_1', 'text' => 'treść']]]),
        ]);

        $payload = $client->call('mempalace_search', ['query' => 'cokolwiek', 'wing' => 'alfa']);

        self::assertSame('drawer_1', $payload['results'][0]['drawer_id']);
    }

    public function testTheTokenTravelsInTheAuthorizationHeader(): void
    {
        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'] ?? []];

            return new MockResponse($this->envelope(['ok' => true]));
        });

        $this->client($http)->call('mempalace_status', []);

        self::assertSame('POST', $seen['method'] ?? null);
        self::assertSame('http://mempalace:8765/mcp', $seen['url'] ?? null);
        self::assertStringContainsString(
            'Bearer ' . self::TOKEN,
            implode("\n", array_map(
                static fn (array $lines): string => implode("\n", $lines),
                $seen['headers'] ?? [],
            )),
        );
    }

    public function testErrorInsideTheToolPayloadIsAFailureAndNotAnEmptyResult(): void
    {
        // Exactly the shape a stopped embedding service produces: status 200,
        // no JSON-RPC error, an empty result list and the real cause buried in
        // the payload.
        $client = $this->clientAnswering([
            $this->envelope(['results' => [], 'error' => 'embedding service unreachable']),
        ]);

        try {
            $client->call('mempalace_search', ['query' => 'cokolwiek', 'wing' => 'alfa']);
            self::fail('a tool reporting its own failure must not look like an empty result');
        } catch (MemPalaceUnavailable $e) {
            self::assertStringContainsString('embedding service unreachable', $e->getMessage());
        }
    }

    public function testJsonRpcErrorEnvelopeIsAFailure(): void
    {
        $client = $this->clientAnswering([
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32601, 'message' => 'Unknown tool']], \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(MemPalaceUnavailable::class);
        $client->call('mempalace_nieistniejace', []);
    }

    public function testServerErrorIsAFailure(): void
    {
        $client = $this->clientAnswering([new MockResponse('nginx error', ['http_code' => 502])]);

        $this->expectException(MemPalaceUnavailable::class);
        $client->call('mempalace_status', []);
    }

    public function testUnparseableBodyIsAFailure(): void
    {
        $client = $this->clientAnswering(['<html>pomyłka w konfiguracji nginxa</html>']);

        $this->expectException(MemPalaceUnavailable::class);
        $client->call('mempalace_status', []);
    }

    public function testAReadIsRetriedAndEventuallySucceeds(): void
    {
        $client = $this->clientAnswering([
            new MockResponse('', ['http_code' => 503]),
            $this->envelope(['results' => []]),
        ]);

        $payload = $client->call('mempalace_search', ['query' => 'x', 'wing' => 'alfa'], retryable: true);

        self::assertSame([], $payload['results']);
    }

    public function testAWriteIsNeverRetried(): void
    {
        // The important half of the retry rule. A repeated add_drawer files the
        // same content twice, and no later check can tell that duplicate from
        // one somebody saved on purpose.
        $attempts = 0;
        $http = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('', ['http_code' => 503]);
        });

        try {
            $this->client($http)->call('mempalace_add_drawer', ['wing' => 'alfa', 'room' => 'technical', 'content' => 'treść']);
            self::fail('an unavailable palace must fail the write');
        } catch (MemPalaceUnavailable) {
            self::assertSame(1, $attempts, 'a write must be attempted exactly once');
        }
    }

    public function testAFailureMessageNeverQuotesTheToken(): void
    {
        $client = $this->clientAnswering([new MockResponse('', ['http_code' => 500])]);

        try {
            $client->call('mempalace_status', []);
            self::fail('expected a failure');
        } catch (MemPalaceUnavailable $e) {
            self::assertStringNotContainsString(
                self::TOKEN,
                $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''),
                'the palace token grants access to every wing — it must never reach a log',
            );
        }
    }

    public function testMissingDrawerIsDistinguishedFromAnOutage(): void
    {
        $client = $this->clientAnswering([$this->envelope(['error' => 'Drawer not found'])]);

        $outcome = $client->tryCall('mempalace_get_drawer', ['drawer_id' => 'drawer_nieznany']);

        self::assertTrue($outcome->failed());
        self::assertTrue($outcome->isMissing(), '"not found" must not be reported as an unavailable palace');
    }

    public function testAnUnrecognisedToolErrorCountsAsAnOutage(): void
    {
        $client = $this->clientAnswering([$this->envelope(['error' => 'pgvector connection refused'])]);

        $outcome = $client->tryCall('mempalace_get_drawer', ['drawer_id' => 'drawer_1']);

        self::assertTrue($outcome->failed());
        self::assertFalse($outcome->isMissing(), 'an unknown failure must not be softened into "no such drawer"');
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
     * @param list<string|MockResponse> $responses
     */
    private function clientAnswering(array $responses): MemPalaceClient
    {
        return $this->client(new MockHttpClient(array_map(
            static fn (string|MockResponse $r): MockResponse => $r instanceof MockResponse ? $r : new MockResponse($r),
            $responses,
        )));
    }

    private function client(MockHttpClient $http): MemPalaceClient
    {
        return new MemPalaceClient($http, 'http://mempalace:8765', self::TOKEN, new NullLogger(), timeoutSeconds: 1.0);
    }
}
