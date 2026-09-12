<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The health endpoint is the first thing Docker and monitoring ask about.
 *
 * It reports each dependency separately, because from the outside every
 * failure looks the same — the API answers while nothing works. Telling a dead
 * database apart from unreachable memory is the whole point of this endpoint.
 */
final class HealthTest extends WebTestCase
{
    public function testReportsStatusOfEveryDependency(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        // Deliberately not asserting 200: this test checks the CONTRACT — that
        // every dependency is reported separately — not whether the world
        // happens to be up. Requiring the memory service here would force CI
        // to download a 2 GB embedding model to run a unit-speed test suite.
        // "Does the whole system come up" is answered by the nightly run.
        self::assertContains($client->getResponse()->getStatusCode(), [200, 503]);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('database', $payload, 'database status missing');
        self::assertArrayHasKey('mempalace', $payload, 'memory service status missing');
    }

    public function testRequiresNoAuthentication(): void
    {
        // Monitoring and the Docker health check carry no token, and must not
        // need one: were this endpoint behind authentication, an auth outage
        // would be indistinguishable from a total application outage.
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
        self::assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    public function testReportsUnhealthyWithServiceUnavailableCode(): void
    {
        // 503 rather than 200-with-a-flag: Docker and load balancers read the
        // status code, not the body. A degraded service that answers 200 keeps
        // receiving traffic it cannot serve.
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $expected = 'healthy' === $payload['status'] ? 200 : 503;
        self::assertSame($expected, $client->getResponse()->getStatusCode());
    }
}
