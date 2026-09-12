<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sign-in route.
 *
 * The body is never executed: the `json_login` firewall intercepts this path
 * and answers with a token or a refusal. The route exists because Symfony needs
 * `check_path` to resolve to something — without it the request 404s before the
 * firewall ever sees it.
 */
final class LoginController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Uwierzytelnianie nie zostało przechwycone przez firewall.'],
            500,
        );
    }
}
