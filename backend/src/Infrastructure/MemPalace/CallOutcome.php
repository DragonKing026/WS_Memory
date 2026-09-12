<?php

declare(strict_types=1);

namespace App\Infrastructure\MemPalace;

/**
 * What one MCP tool call came back with.
 *
 * Exists because MemPalace reports a tool's own failure INSIDE the payload, not
 * in the JSON-RPC envelope: the HTTP status is 200, the envelope has no `error`
 * key, and the failure sits next to an empty result list. We learned this the
 * hard way while testing Polish semantics — with the embedding service stopped,
 * the response was indistinguishable from a model that simply found nothing,
 * and the false diagnosis cost hours (test/sprawdz_odpowiedz.py says the same).
 *
 * So a tool error is data here, not an exception, and the caller decides. Most
 * callers want it to be fatal (self::payloadOrFail); fetching a single drawer
 * wants "not found" to be a plain null instead.
 */
final readonly class CallOutcome
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $tool,
        public array $payload,
        public ?string $error = null,
    ) {
    }

    public function failed(): bool
    {
        return null !== $this->error;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemPalaceUnavailable
     */
    public function payloadOrFail(): array
    {
        if (null !== $this->error) {
            throw MemPalaceUnavailable::toolFailed($this->tool, $this->error);
        }

        return $this->payload;
    }

    /**
     * Whether the tool is telling us the thing simply is not there.
     *
     * Pattern matching on an error message is unpleasant, and it is what the
     * protocol leaves us: MemPalace has no separate "not found" signal. Kept in
     * one place so that a version whose wording changes is a one-line fix, and
     * biased towards treating an unrecognised error as a failure — mistaking a
     * real outage for "no such drawer" is the worse direction.
     */
    public function isMissing(): bool
    {
        if (null === $this->error) {
            return false;
        }

        $message = mb_strtolower($this->error);

        foreach (['not found', 'no such', 'does not exist', 'unknown drawer'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
