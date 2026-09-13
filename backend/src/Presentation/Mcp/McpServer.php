<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

use App\Domain\Identity\Actor;
use App\Domain\Instruction\Instruction;
use App\Domain\Instruction\InstructionLibrary;
use App\Domain\Instruction\InstructionUnavailable;
use App\Domain\Instruction\UnknownInstruction;
use App\Domain\Memory\MemoryAccessDenied;
use App\Domain\Memory\MemoryUnavailable;
use Psr\Log\LoggerInterface;

/**
 * The JSON-RPC 2.0 side of the gateway: protocol in, protocol out.
 *
 * Kept apart from the controller so that HTTP — status codes, headers, the token
 * — and the protocol are two separate problems. Everything here is testable by
 * handing it an array.
 *
 * Two surfaces: **tools**, which act on memory in the caller's name, and
 * **resources**, which are the instruction texts from `plugin/shared/` — the same
 * bytes for everybody, read-only, and not audited (see listResources).
 *
 * **Tool failures are answered as JSON-RPC errors, not as successful results
 * carrying an error field.** The MCP specification suggests the opposite
 * (`isError` inside the result), and we deliberately do not follow it (D-023):
 * MemPalace does exactly that, and it cost hours — with the embedding service
 * stopped, its answer was indistinguishable from "found nothing". A client that
 * must inspect a payload to learn whether a call worked will eventually forget to.
 */
final readonly class McpServer
{
    /** The revision we declare. Clients asking for an older known one get theirs back. */
    public const PROTOCOL_VERSION = '2025-06-18';

    private const SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * Bumped when the tool set changes in a way an agent could notice — a removed
     * tool, a renamed argument. Not a release number for the whole application.
     */
    private const SERVER_VERSION = '0.1.0';

    public function __construct(
        private McpToolRegistry $tools,
        private InstructionLibrary $instructions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null null for a notification, which gets no answer
     */
    public function handle(array $request, Actor $actor): ?array
    {
        /** @var mixed $id */
        $id = $request['id'] ?? null;
        $notification = !\array_key_exists('id', $request);

        try {
            if ('2.0' !== ($request['jsonrpc'] ?? null)) {
                throw McpError::of(McpError::INVALID_REQUEST, 'Pole „jsonrpc" musi mieć wartość „2.0".');
            }

            $method = $request['method'] ?? null;
            if (!\is_string($method) || '' === $method) {
                throw McpError::of(McpError::INVALID_REQUEST, 'Brak nazwy metody.');
            }

            $params = \is_array($request['params'] ?? null) ? $request['params'] : [];

            $result = $this->dispatch($method, $params, $actor);
        } catch (McpError $e) {
            return $notification ? null : $this->error($id, $e->rpcCode, $e->getMessage(), $e->data);
        }

        // A notification is a call whose answer nobody is waiting for. Sending
        // one anyway is what makes a client hang on the next request.
        if ($notification) {
            return null;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function dispatch(string $method, array $params, Actor $actor): array
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'tools/list' => $this->listTools(),
            'tools/call' => $this->callTool($params, $actor),
            // The instruction texts from plugin/shared. Read-only and the same for
            // everybody, which is why they need no actor.
            'resources/list' => $this->listResources(),
            'resources/read' => $this->readResource($params),
            // Liveness and the client's "I am ready". Both must be answered
            // rather than refused, or a client treats the server as broken.
            'ping' => [],
            'notifications/initialized', 'notifications/cancelled' => [],
            default => throw McpError::methodNotFound($method),
        };
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        return [
            // Echo a revision the client knows, otherwise state ours. Refusing
            // an unknown revision outright would lock out clients that would
            // work fine — the tool schemas are the same either way.
            'protocolVersion' => \is_string($asked) && \in_array($asked, self::SUPPORTED_PROTOCOLS, true)
                ? $asked
                : self::PROTOCOL_VERSION,
            // stdClass, not an empty array: PHP would serialise [] as `[]`, and
            // `capabilities.tools` has to be an object. Clients that validate the
            // handshake reject the array form.
            'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass()],
            'serverInfo' => ['name' => 'ws_memory', 'version' => self::SERVER_VERSION],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listTools(): array
    {
        return [
            'tools' => array_map(static fn (McpTool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ], $this->tools->all()),
        ];
    }

    /**
     * The instructions the gateway publishes: the recall protocol, the
     * documentation rules, the subagent briefs.
     *
     * Served from the server rather than copied into every plugin packaging
     * (D-013), so changing an instruction is a deployment instead of an update
     * everybody has to install — and a client that is not Claude Code reads the
     * same text.
     *
     * **Neither listing nor reading a resource is written to the audit journal,
     * and that is a decision rather than an omission.** These are static texts,
     * identical for every token, and an MCP client asks for the catalogue on every
     * connection — so the entry would say "somebody connected", not "somebody did
     * something". We have paid for exactly this mechanism once already: an event
     * recorded per request instead of per real action put **20 335** fictitious
     * `user.login` rows into this system's journal, half of everything it held on
     * the day the audit screen was first opened
     * (`src/Infrastructure/Security/LoginAuditSubscriber.php`). A journal whose
     * majority is noise is worse than a short one, because the real entries are
     * somewhere inside it and nobody will find them.
     *
     * The rate limit in McpController does cover these methods like every other
     * one, and it stays that way: a client looping over the catalogue costs the
     * server just as much as one looping over searches.
     *
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function listResources(): array
    {
        return [
            'resources' => array_map(static fn (Instruction $instruction): array => [
                'uri' => $instruction->uri,
                'name' => $instruction->name,
                'title' => $instruction->title,
                'description' => $instruction->description,
                'mimeType' => $instruction->mimeType,
            ], $this->published()),
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function readResource(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!\is_string($uri) || '' === $uri) {
            throw McpError::invalidParams('Brak adresu zasobu w „params.uri".');
        }

        try {
            $read = $this->instructions->read($uri);
        } catch (UnknownInstruction $e) {
            throw McpError::of(McpError::RESOURCE_NOT_FOUND, $e->getMessage());
        } catch (InstructionUnavailable $e) {
            throw $this->instructionsBroken($e);
        }

        return [
            'contents' => [[
                'uri' => $read->instruction->uri,
                'mimeType' => $read->instruction->mimeType,
                'text' => $read->text,
            ]],
        ];
    }

    /**
     * @return list<Instruction>
     *
     * @throws McpError
     */
    private function published(): array
    {
        try {
            return $this->instructions->all();
        } catch (InstructionUnavailable $e) {
            throw $this->instructionsBroken($e);
        }
    }

    /**
     * A published instruction that cannot be read is our fault, not the caller's.
     *
     * Answered as an error and never as an empty document: an instruction served
     * as empty text reads to a model like "there is no protocol", and it carries
     * on. The detail goes to the log, where somebody can see that a container was
     * started without the mount.
     */
    private function instructionsBroken(InstructionUnavailable $cause): McpError
    {
        $this->logger->error('Nie udało się odczytać instrukcji wystawianych jako zasoby MCP.', [
            'reason' => $cause->getMessage(),
        ]);

        return McpError::of(-32603, 'Nie udało się odczytać instrukcji. Szczegóły są w dzienniku serwera.');
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function callTool(array $params, Actor $actor): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            throw McpError::invalidParams('Brak nazwy narzędzia w „params.name".');
        }

        $tool = $this->tools->find($name);
        if (null === $tool) {
            // Named as method-not-found rather than invalid-params: from the
            // client's point of view it asked for something that does not exist.
            throw McpError::of(
                McpError::METHOD_NOT_FOUND,
                \sprintf('Nie ma narzędzia „%s". Wywołaj tools/list, żeby zobaczyć dostępne.', $name),
            );
        }

        $arguments = $params['arguments'] ?? [];
        if (!\is_array($arguments)) {
            throw McpError::invalidParams('Pole „params.arguments" musi być obiektem.');
        }

        /** @var array<string, mixed> $arguments */
        $payload = $this->execute($tool, $actor, $arguments);

        // MCP's own envelope: a list of content parts. The payload travels as
        // JSON inside a text part, which is what every MCP server does and what
        // clients are built to unwrap.
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT),
            ]],
        ];
    }

    /**
     * Runs the tool and translates every failure into a code from docs/03.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws McpError
     */
    private function execute(McpTool $tool, Actor $actor, array $arguments): array
    {
        try {
            return $tool->call($actor, $arguments);
        } catch (McpError $e) {
            throw $e;
        } catch (MemoryAccessDenied $e) {
            throw McpError::of(McpError::WRITE_DENIED, $e->getMessage());
        } catch (MemoryUnavailable $e) {
            // Never softened into an empty result: "I could not look" and "there
            // is nothing" lead an agent to opposite next actions.
            $this->logger->error('Pamięć nie odpowiedziała na wywołanie MCP.', [
                'tool' => $tool->name(),
                'reason' => $e->getMessage(),
            ]);

            throw McpError::of(
                McpError::MEMORY_UNAVAILABLE,
                'Pamięć jest chwilowo niedostępna. To nie znaczy, że nic nie znaleziono — nie udało się sprawdzić.',
            );
        } catch (\InvalidArgumentException $e) {
            // Value objects refusing a malformed argument: an empty space slug,
            // a query over the length the palace accepts.
            throw McpError::invalidParams($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Narzędzie MCP zawiodło.', [
                'tool' => $tool->name(),
                'exception' => $e,
            ]);

            // Deliberately vague to the caller, precise in the log. A stack
            // trace or a database message in an answer is a disclosure.
            throw McpError::of(-32603, 'Wewnętrzny błąd serwera. Szczegóły są w dzienniku serwera.');
        }
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if (null !== $data) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => \is_int($id) || \is_string($id) ? $id : null,
            'error' => $error,
        ];
    }
}
