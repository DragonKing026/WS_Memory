<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Identity\AgentTokenDirectory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates /mcp with an agent token instead of a JWT.
 *
 * A separate authenticator rather than the JWT firewall, for the reasons in
 * docs/03: an agent's credential has to be revocable at once, live for months and
 * carry a narrowing scope. A JWT does none of those.
 *
 * The resolved identity is placed on the request as an attribute — that is how the
 * controller learns which token called. The Symfony token carries only the owner,
 * and the owner is not the whole answer: an agent is its owner **narrowed**, and
 * the narrowing lives on the credential.
 *
 * Every failure answers identically. "No such token", "revoked", "expired" and
 * "owner deactivated" are one answer, because telling them apart helps only
 * somebody working through a list of guesses.
 */
final class AgentTokenAuthenticator extends AbstractAuthenticator
{
    /** Where the controller finds the resolved identity. */
    public const IDENTITY_ATTRIBUTE = 'ws_agent_identity';

    public function __construct(
        private readonly AgentTokenDirectory $directory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Narrowed from the interface's ?bool: null there means "decide lazily", and
     * the path prefix is knowable at once.
     */
    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/mcp');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException(
                'Brak tokena. Podaj nagłówek Authorization: Bearer <token agenta>.',
            );
        }

        $identity = $this->directory->resolve(substr($header, 7));
        if (null === $identity) {
            throw new CustomUserMessageAuthenticationException('Token jest nieważny, unieważniony albo wygasł.');
        }

        $request->attributes->set(self::IDENTITY_ATTRIBUTE, $identity);

        // SelfValidatingPassport: the secret has already been checked against its
        // hash by the directory, so there is no password to verify. The owner is
        // still loaded, because that is what makes the firewall's user checker run
        // — and that check is what stops a deactivated owner's tokens without
        // anybody revoking them one by one.
        return new SelfValidatingPassport(
            new UserBadge(
                $identity->actor->userId,
                fn (string $userId): UserInterface => $this->loadOwner($userId),
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // WWW-Authenticate so a client can tell "your credential is wrong" from
        // "this endpoint is down". Whoever configured the agent needs to react
        // differently to the two.
        return new JsonResponse(
            ['error' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="ws_memory"'],
        );
    }

    /**
     * Loads the owner by id.
     *
     * A loader of our own, because the firewall's user provider looks accounts up
     * by e-mail and a token resolves to an identifier. Going through the provider
     * would mean carrying the owner's e-mail around inside the domain just to
     * satisfy a lookup.
     */
    private function loadOwner(string $userId): UserInterface
    {
        $owner = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$owner instanceof User) {
            // The directory resolved the token a moment ago, so this means the
            // account disappeared in between. Same answer as an unknown token.
            throw new UserNotFoundException();
        }

        return $owner;
    }
}
