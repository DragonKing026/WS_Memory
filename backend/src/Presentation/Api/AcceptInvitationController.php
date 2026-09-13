<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Identity\PasswordPolicy;
use App\Application\Invitation\AcceptInvitation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turning an invitation into an account.
 *
 * Public by necessity — the caller has no account yet, which is the point.
 * The invitation token is the only credential, so the password policy is
 * enforced here rather than trusted to the frontend.
 *
 * The policy itself lives in PasswordPolicy, because `ws:user:password` sets
 * passwords too and the rule has to be the same one, not a copy of it.
 */
final readonly class AcceptInvitationController
{
    public function __construct(
        private AcceptInvitation $acceptInvitation,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/invitations/accept', name: 'api_invitations_accept', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getPayload();

        $violations = $this->validator->validate(
            [
                'token' => (string) $payload->get('token'),
                'displayName' => (string) $payload->get('displayName'),
                'password' => (string) $payload->get('password'),
            ],
            new Assert\Collection([
                'token' => [new Assert\NotBlank()],
                'displayName' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 120)],
                // The one password rule, shared with the console. Twelve
                // characters, no composition rules, and nothing known from a
                // public breach — spelled out in PasswordPolicy.
                'password' => PasswordPolicy::constraints(),
            ]),
        );

        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = [
                    'field' => trim($violation->getPropertyPath(), '[]'),
                    'message' => $violation->getMessage(),
                ];
            }

            return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $user = ($this->acceptInvitation)(
                (string) $payload->get('token'),
                (string) $payload->get('displayName'),
                (string) $payload->get('password'),
            );
        } catch (\DomainException $e) {
            // 422 rather than 404: the same answer for an unknown, a used and
            // an expired token, so the endpoint cannot be used to probe which
            // invitations exist.
            return new JsonResponse(
                ['errors' => [['field' => 'token', 'message' => $e->getMessage()]]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
        ]);
    }
}
