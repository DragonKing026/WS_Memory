<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Application\AgentToken\IssueAgentToken;
use App\Domain\Identity\AgentTokenDirectory;
use App\Infrastructure\Security\AgentTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which requests the agent-token authenticator claims — and, more importantly,
 * which it must not.
 *
 * This one method is the whole boundary between "agents on /mcp, people on /api"
 * and its single documented exception, `/api/publish` (D-036). It shares the `api`
 * firewall with the JWT listener, so claiming one request too many would mean
 * answering for a signed-in person; claiming one too few would lock out the outbox
 * that has to run unattended (D-015).
 *
 * Asserted here rather than through HTTP because the rule is a pure function of the
 * path and one header, and because the endpoint's own test needs a live palace —
 * which would mean this boundary went unchecked on ordinary commits.
 */
final class AgentTokenAuthenticatorSupportsTest extends TestCase
{
    private const AGENT = 'Bearer ' . IssueAgentToken::PREFIX . 'a1b2c3d4e5f6';
    private const JWT = 'Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.payload.signature';

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function requests(): iterable
    {
        // /mcp accepts nothing but an agent token, so it is claimed whatever
        // arrives — a caller with no credential should be told which one is missing.
        yield '/mcp bez nagłówka' => ['/mcp', null, true];
        yield '/mcp z tokenem agenta' => ['/mcp', self::AGENT, true];
        yield '/mcp z JWT' => ['/mcp', self::JWT, true];

        yield 'publikacja z tokenem agenta' => ['/api/publish', self::AGENT, true];

        // The one that matters most: a signed-in person publishing from the browser
        // must fall through to the JWT listener, not be refused by this one.
        yield 'publikacja z JWT zostaje dla JWT' => ['/api/publish', self::JWT, false];
        yield 'publikacja bez nagłówka' => ['/api/publish', null, false];

        yield 'wycofanie partii z tokenem agenta' => ['/api/publish/0199f0c0-0000-7000-8000-000000000002/revert', self::AGENT, true];

        // The prefix must be a path segment, not a string prefix. Without the
        // check for the separator, every route whose name merely starts the same
        // way would quietly accept agent tokens.
        yield 'trasa o podobnej nazwie' => ['/api/publishers', self::AGENT, false];
        yield 'inna trasa API z tokenem agenta' => ['/api/documents', self::AGENT, false];
        yield 'logowanie z tokenem agenta' => ['/api/login', self::AGENT, false];
    }

    #[DataProvider('requests')]
    public function testClaimsExactlyTheRequestsItShould(string $path, ?string $authorization, bool $expected): void
    {
        $authenticator = new AgentTokenAuthenticator(
            $this->createMock(AgentTokenDirectory::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $request = Request::create($path);
        if (null !== $authorization) {
            $request->headers->set('Authorization', $authorization);
        }

        self::assertSame($expected, $authenticator->supports($request));
    }
}
