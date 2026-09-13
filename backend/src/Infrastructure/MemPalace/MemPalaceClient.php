<?php

declare(strict_types=1);

namespace App\Infrastructure\MemPalace;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A thin JSON-RPC 2.0 client for the MemPalace MCP endpoint.
 *
 * Thin on purpose: it knows the wire format and nothing about spaces, actors or
 * permissions. Those live in MemoryService, and a client that also knew about
 * them would be a second place where a filter could be forgotten.
 *
 * Two behaviours are worth reading before changing anything here.
 *
 * **Retries are for reads only.** A retried search costs a second query. A
 * retried write can file the same drawer twice, and the duplicate is impossible
 * to distinguish from content somebody really did save twice — MemPalace's own
 * duplicate check compares content, not intent. When a write times out we do not
 * know whether it landed, so we report a failure and let the caller decide;
 * guessing would trade a visible error for an invisible duplicate.
 *
 * **The token never reaches a log.** It is a shared secret granting full access
 * to every wing in the palace (inviolable rule 1), and the most common way such
 * a secret escapes is an exception message quoting the request that failed.
 * Nothing in this class puts headers into a message.
 */
final readonly class MemPalaceClient
{
    private const PROTOCOL_VERSION = '2.0';

    /** The MCP revision we negotiate in the handshake — JSON-RPC's version is the one above. */
    private const MCP_PROTOCOL_VERSION = '2025-06-18';

    /** Backoff between read attempts, in milliseconds. Short: a request is waiting. */
    private const RETRY_BACKOFF_MS = [100, 300];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        #[\SensitiveParameter]
        private string $token,
        private LoggerInterface $logger,
        private float $timeoutSeconds = 15.0,
    ) {
    }

    /**
     * Calls a tool and insists on a usable answer.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws MemPalaceUnavailable
     */
    public function call(string $tool, array $arguments, bool $retryable = false): array
    {
        return $this->tryCall($tool, $arguments, $retryable)->payloadOrFail();
    }

    /**
     * Calls a tool and hands back whatever came of it, tool errors included.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws MemPalaceUnavailable on transport, HTTP or protocol failures — those are never the caller's business
     */
    public function tryCall(string $tool, array $arguments, bool $retryable = false): CallOutcome
    {
        $attempts = $retryable ? 1 + \count(self::RETRY_BACKOFF_MS) : 1;

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                return $this->attempt($tool, $arguments);
            } catch (MemPalaceUnavailable $e) {
                if ($attempt === $attempts) {
                    throw $e;
                }

                // Logged at notice, not warning: a single retried read is normal
                // during a restart of the sidecar and should not page anybody.
                $this->logger->notice('Ponawiam odczyt z pamięci.', [
                    'tool' => $tool,
                    'attempt' => $attempt,
                    'reason' => $e->getMessage(),
                ]);

                usleep(self::RETRY_BACKOFF_MS[$attempt - 1] * 1000);
            }
        }

        // Unreachable: the loop either returns or rethrows on its last attempt.
        throw MemPalaceUnavailable::malformed($tool, 'wyczerpano próby bez rozstrzygnięcia');
    }

    /**
     * What the server says about itself in the MCP handshake.
     *
     * Lives here rather than in a second client because the wire format, the base
     * URL, the token and the rule that none of them reach a log are all already
     * settled in this class — and a second client is a second place to forget the
     * last of those.
     *
     * `initialize` is used because MemPalace has no `/version` route, and because
     * it answers with what is actually RUNNING rather than with what somebody
     * meant to build. Not retried and not routed through CallOutcome: this is the
     * protocol handshake, not a tool, so there is no payload envelope to peel and
     * no in-band tool error to disentangle from a transport one.
     *
     * @return array<string, mixed> the `result.serverInfo` object, e.g. `{name, version}`
     *
     * @throws MemPalaceUnavailable
     */
    public function serverInfo(): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/mcp', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    // The streaming type is offered because the MCP HTTP
                    // transport may answer either way; this server replies with
                    // plain JSON, and a client that accepted only that would be
                    // relying on it never changing its mind.
                    'Accept' => 'application/json, text/event-stream',
                    'Authorization' => 'Bearer ' . $this->token,
                ],
                'json' => [
                    'jsonrpc' => self::PROTOCOL_VERSION,
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                        'capabilities' => new \stdClass(),
                        'clientInfo' => ['name' => 'ws-memory', 'version' => '1'],
                    ],
                ],
                'timeout' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw MemPalaceUnavailable::httpStatus('initialize', $status);
            }

            $body = $response->getContent(throw: false);
        } catch (MemPalaceUnavailable $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw MemPalaceUnavailable::transport('initialize', $e);
        }

        try {
            /** @var mixed $envelope */
            $envelope = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw MemPalaceUnavailable::malformed('initialize', 'odpowiedź nie jest JSON-em: ' . $e->getMessage());
        }

        if (!\is_array($envelope) || !\is_array($envelope['result'] ?? null)) {
            throw MemPalaceUnavailable::malformed('initialize', 'brak pola result');
        }

        $serverInfo = $envelope['result']['serverInfo'] ?? null;
        if (!\is_array($serverInfo)) {
            throw MemPalaceUnavailable::malformed('initialize', 'brak pola result.serverInfo');
        }

        /** @var array<string, mixed> $serverInfo */
        return $serverInfo;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws MemPalaceUnavailable
     */
    private function attempt(string $tool, array $arguments): CallOutcome
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/mcp', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->token,
                ],
                'json' => [
                    'jsonrpc' => self::PROTOCOL_VERSION,
                    // A constant id is fine and honest: one request per
                    // connection, answered before the next is sent. Correlating
                    // responses would need a counter that says something true.
                    'id' => 1,
                    'method' => 'tools/call',
                    'params' => ['name' => $tool, 'arguments' => $arguments],
                ],
                'timeout' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw MemPalaceUnavailable::httpStatus($tool, $status);
            }

            $body = $response->getContent(throw: false);
        } catch (MemPalaceUnavailable $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw MemPalaceUnavailable::transport($tool, $e);
        }

        return $this->interpret($tool, $body);
    }

    /**
     * Unwraps the two envelopes MCP puts around a tool's answer.
     *
     * A successful response is a JSON-RPC envelope whose `result.content` is a
     * list of text parts; concatenating them yields the tool's own JSON. Both
     * layers have to be peeled before anything can be believed.
     *
     * @throws MemPalaceUnavailable
     */
    private function interpret(string $tool, string $body): CallOutcome
    {
        if ('' === trim($body)) {
            throw MemPalaceUnavailable::malformed($tool, 'puste ciało odpowiedzi');
        }

        try {
            /** @var mixed $envelope */
            $envelope = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw MemPalaceUnavailable::malformed($tool, 'odpowiedź nie jest JSON-em: ' . $e->getMessage());
        }

        if (!\is_array($envelope)) {
            throw MemPalaceUnavailable::malformed($tool, 'odpowiedź nie jest obiektem JSON');
        }

        if (isset($envelope['error'])) {
            $error = $envelope['error'];
            $message = \is_array($error) && isset($error['message']) && \is_scalar($error['message'])
                ? (string) $error['message']
                : json_encode($error, \JSON_UNESCAPED_UNICODE);

            throw MemPalaceUnavailable::toolFailed($tool, (string) $message);
        }

        $result = $envelope['result'] ?? null;
        if (!\is_array($result)) {
            throw MemPalaceUnavailable::malformed($tool, 'brak pola result');
        }

        $text = '';
        foreach (\is_array($result['content'] ?? null) ? $result['content'] : [] as $part) {
            if (\is_array($part) && \is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if ('' === trim($text)) {
            throw MemPalaceUnavailable::malformed($tool, 'result.content nie zawiera treści');
        }

        /** @var mixed $payload */
        $payload = json_decode($text, true);
        if (!\is_array($payload)) {
            // Some tools answer in prose. Not an error — just not a structure.
            return new CallOutcome($tool, ['text' => $text]);
        }

        /** @var array<string, mixed> $payload */
        $error = $payload['error'] ?? null;
        if (\is_string($error) && '' !== trim($error)) {
            return new CallOutcome($tool, $payload, $error);
        }
        if (\is_array($error) && [] !== $error) {
            return new CallOutcome($tool, $payload, (string) json_encode($error, \JSON_UNESCAPED_UNICODE));
        }

        // MCP's own flag, set when a tool raises rather than returns.
        if (true === ($result['isError'] ?? false)) {
            return new CallOutcome($tool, $payload, $text);
        }

        return new CallOutcome($tool, $payload);
    }
}
