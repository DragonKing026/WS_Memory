<?php

declare(strict_types=1);

namespace App\Application\AgentToken;

/**
 * The result of issuing an agent token.
 *
 * Carries the plain secret, which exists in this object and nowhere else — the
 * database holds only its hash. There is no second chance to read it, and that is
 * the point: a token that can be re-read from the application is a token that a
 * leaked backup hands over.
 */
final readonly class IssuedAgentToken
{
    /**
     * @param list<string>|null $spaceScope
     */
    public function __construct(
        public string $tokenId,
        public string $label,
        public string $plainToken,
        public ?array $spaceScope,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The one command that connects an agent to this server.
     *
     * Printed rather than explained, because the alternative is everyone
     * reconstructing it from the documentation and getting the header wrong.
     */
    public function claudeCodeCommand(string $baseUrl): string
    {
        return \sprintf(
            'claude mcp add --transport http ws_memory %s/mcp --header "Authorization: Bearer %s"',
            rtrim($baseUrl, '/'),
            $this->plainToken,
        );
    }
}
