<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Health\HealthChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health of the backend and its dependencies.
 *
 * Polled by the Docker health check and by monitoring, hence deliberately
 * UNAUTHENTICATED: otherwise an authentication outage would look like a total
 * application outage, and monitoring would have to carry a token.
 *
 * The controller does not know what gets checked — probes are added as tagged
 * services, never by editing this file.
 */
final readonly class HealthController
{
    public function __construct(private HealthChecker $checker)
    {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $report = $this->checker->check();

        return new JsonResponse(
            $report->toArray(),
            $report->isHealthy() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
