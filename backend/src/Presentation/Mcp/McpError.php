<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

/**
 * A JSON-RPC error the gateway answers with.
 *
 * The codes above -32000 are ours and are listed in docs/03-mcp-gateway.md.
 * What is NOT here is equally deliberate: there is no "space forbidden" code. A
 * read outside one's permissions answers with an empty result, because the
 * message "you have no access to the space HR" confirms that an HR space exists
 * and is worth asking about (inviolable rule 7).
 */
final class McpError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;

    /** Write attempted without the writer role in that space. */
    public const WRITE_DENIED = -32003;

    /** The space queues agent writes and this tool writes directly. */
    public const PROPOSAL_REQUIRED = -32004;

    /** Too many calls from one token in one window. */
    public const RATE_LIMITED = -32005;

    /** Memory did not answer. Distinct from an empty result, on purpose. */
    public const MEMORY_UNAVAILABLE = -32010;

    /** @param array<string, mixed>|null $data */
    private function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, $rpcCode);
    }

    /** @param array<string, mixed>|null $data */
    public static function of(int $code, string $message, ?array $data = null): self
    {
        return new self($code, $message, $data);
    }

    public static function invalidParams(string $message): self
    {
        return new self(self::INVALID_PARAMS, $message);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, \sprintf('Nieznana metoda „%s".', $method));
    }
}
