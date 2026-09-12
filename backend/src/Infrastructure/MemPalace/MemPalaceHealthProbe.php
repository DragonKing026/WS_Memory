<?php

declare(strict_types=1);

namespace App\Infrastructure\MemPalace;

use App\Domain\Health\DependencyStatus;
use App\Domain\Health\HealthProbe;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter: checks whether the memory service responds.
 *
 * Queries /healthz — the only MemPalace route that needs no authentication.
 * The short timeout is deliberate: a health check must answer fast even when
 * memory hangs. A hanging check is worse than a negative one, because Docker
 * cannot tell it apart from a working service until its own timeout expires.
 */
final readonly class MemPalaceHealthProbe implements HealthProbe
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
    ) {
    }

    public function name(): string
    {
        return 'mempalace';
    }

    public function check(): DependencyStatus
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                rtrim($this->baseUrl, '/') . '/healthz',
                ['timeout' => self::TIMEOUT_SECONDS],
            );

            return 200 === $response->getStatusCode()
                ? DependencyStatus::available()
                : DependencyStatus::unavailable('HTTP ' . $response->getStatusCode());
        } catch (\Throwable $e) {
            return DependencyStatus::unavailable($e->getMessage());
        }
    }
}
