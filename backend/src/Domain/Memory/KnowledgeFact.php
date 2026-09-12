<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * One triple in the knowledge graph: subject → predicate → object.
 *
 * Carries an optional validity window, because the graph records what was true
 * when, not only what is true now. A fact that ended is closed with validTo
 * rather than deleted — the history of a project's decisions is exactly the
 * part worth keeping.
 */
final readonly class KnowledgeFact
{
    public function __construct(
        public string $subject,
        public string $predicate,
        public string $object,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
    ) {
        foreach (['subject' => $subject, 'predicate' => $predicate, 'object' => $object] as $name => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('A knowledge-graph fact needs a non-empty %s.', $name));
            }
        }
    }

    /**
     * A stable digest of the triple, ignoring its validity window.
     *
     * Case- and whitespace-insensitive: the palace is queried with whatever an
     * agent typed, and "Tenanto" written twice with different capitalisation is
     * one fact, not two. The window is left out on purpose — extending a fact's
     * validity must not change its identity, or the extension would register as
     * a new fact and the old row would linger unreachable.
     */
    public function fingerprint(): string
    {
        $parts = array_map(
            static fn (string $part): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $part) ?? $part)),
            [$this->subject, $this->predicate, $this->object],
        );

        return hash('sha256', implode("\0", $parts));
    }

    public function describe(): string
    {
        return \sprintf('%s → %s → %s', $this->subject, $this->predicate, $this->object);
    }
}
