<?php

declare(strict_types=1);

namespace App\Infrastructure\MemPalace;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\MemoryStore;
use App\Domain\Memory\PalaceWing;

/**
 * Adapter: the memory port, spoken in MemPalace's MCP dialect.
 *
 * This is the only class in the system that knows tool names such as
 * `mempalace_search`, and the only one that knows what shape their answers have.
 * Everything above it works with fragments, wings and facts. That boundary is
 * what makes D-001 — MemPalace as a dependency, treated as a black box — a
 * statement about the code rather than a hope: upgrading the palace across a
 * breaking change touches this file and no other.
 *
 * The response mapping is written to tolerate a field that moves or disappears:
 * a missing similarity score is null, an unreadable date is null, a fact missing
 * its subject is skipped. What is NOT tolerated is a missing identifier after a
 * write — without it there is nothing to book in the registry, and an unbooked
 * write is content nobody will ever find again.
 */
final readonly class McpMemoryStore implements MemoryStore
{
    public function __construct(private MemPalaceClient $client)
    {
    }

    public function search(PalaceWing $wing, MemoryQuery $query): array
    {
        $arguments = [
            'query' => $query->text,
            // Present in every single call, by the shape of the port: a wing
            // cannot be null and cannot be empty (inviolable rule 3).
            'wing' => $wing->value,
            'limit' => $query->limit,
        ];

        if (null !== $room = $query->room()) {
            $arguments['room'] = $room;
        }
        if (null !== $query->since) {
            $arguments['since'] = $query->since->format('Y-m-d\TH:i:s');
        }
        if (null !== $query->before) {
            $arguments['before'] = $query->before->format('Y-m-d\TH:i:s');
        }
        if (null !== $query->maxDistance) {
            $arguments['max_distance'] = $query->maxDistance;
        }

        $payload = $this->client->call('mempalace_search', $arguments, retryable: true);

        $fragments = [];
        foreach (\is_array($payload['results'] ?? null) ? $payload['results'] : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $fragment = $this->toFragment($row, $wing);
            if (null !== $fragment) {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    public function fetch(DrawerId $drawer): ?MemoryFragment
    {
        $outcome = $this->client->tryCall(
            'mempalace_get_drawer',
            ['drawer_id' => $drawer->value],
            retryable: true,
        );

        // A drawer that is not there is an answer, not a failure. Telling the
        // two apart matters upstream: "gone" is a drift between our registry and
        // the palace, while "unavailable" is a sidecar to restart.
        if ($outcome->isMissing()) {
            return null;
        }

        $payload = $outcome->payloadOrFail();
        $wingName = $this->stringOrNull($payload['wing'] ?? null);

        if (null === $wingName) {
            return null;
        }

        return $this->toFragment($payload, new PalaceWing($wingName));
    }

    public function store(
        PalaceWing $wing,
        MemoryKind $kind,
        string $content,
        string $addedBy,
        ?string $sourceFile = null,
    ): DrawerId {
        $arguments = [
            'wing' => $wing->value,
            'room' => $kind->room(),
            'content' => $content,
            'added_by' => $addedBy,
        ];

        if (null !== $sourceFile) {
            $arguments['source_file'] = $sourceFile;
        }

        // Not retryable: see MemPalaceClient. A repeated write files a second
        // drawer, and nothing afterwards can tell it from content saved twice
        // on purpose.
        $payload = $this->client->call('mempalace_add_drawer', $arguments);

        return $this->drawerIdFrom($payload, 'mempalace_add_drawer');
    }

    public function queryFacts(string $entity, ?string $direction = null): array
    {
        $arguments = ['entity' => $entity];
        if (null !== $direction) {
            $arguments['direction'] = $direction;
        }

        $payload = $this->client->call('mempalace_kg_query', $arguments, retryable: true);

        $facts = [];
        foreach (\is_array($payload['facts'] ?? null) ? $payload['facts'] : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $subject = $this->stringOrNull($row['subject'] ?? null);
            $predicate = $this->stringOrNull($row['predicate'] ?? null);
            $object = $this->stringOrNull($row['object'] ?? null);

            // A triple missing a leg is skipped rather than guessed at. The
            // graph is queried to answer questions about people and projects;
            // a half-fact would be answered as if it were whole.
            if (null === $subject || null === $predicate || null === $object) {
                continue;
            }

            $facts[] = new KnowledgeFact(
                $subject,
                $predicate,
                $object,
                $this->dateOrNull($row['valid_from'] ?? null),
                $this->dateOrNull($row['valid_to'] ?? null),
            );
        }

        return $facts;
    }

    public function addFact(KnowledgeFact $fact): void
    {
        $arguments = [
            'subject' => $fact->subject,
            'predicate' => $fact->predicate,
            'object' => $fact->object,
        ];

        if (null !== $fact->validFrom) {
            $arguments['valid_from'] = $fact->validFrom->format('Y-m-d');
        }
        if (null !== $fact->validTo) {
            $arguments['valid_to'] = $fact->validTo->format('Y-m-d');
        }

        $this->client->call('mempalace_kg_add', $arguments);
    }

    public function writeDiary(
        PalaceWing $wing,
        string $agentName,
        string $entry,
        ?string $topic = null,
    ): DrawerId {
        $arguments = [
            'agent_name' => $agentName,
            'entry' => $entry,
            // Without this the palace files the entry into wing_{agent_name} —
            // a wing outside our space mapping, which no permission check would
            // ever let anybody read again.
            'wing' => $wing->value,
        ];

        if (null !== $topic) {
            $arguments['topic'] = $topic;
        }

        $payload = $this->client->call('mempalace_diary_write', $arguments);

        return $this->drawerIdFrom($payload, 'mempalace_diary_write');
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toFragment(array $row, PalaceWing $wing): ?MemoryFragment
    {
        $id = $this->stringOrNull($row['drawer_id'] ?? null);
        // Search calls the content "text"; get_drawer calls it "content".
        $content = $this->stringOrNull($row['content'] ?? null) ?? $this->stringOrNull($row['text'] ?? null);

        if (null === $id || null === $content) {
            return null;
        }

        $metadata = \is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        return new MemoryFragment(
            new DrawerId($id),
            $wing,
            $this->stringOrNull($row['room'] ?? null) ?? 'general',
            $content,
            sourceFile: $this->stringOrNull($row['source_path'] ?? null)
                ?? $this->stringOrNull($row['source_file'] ?? null)
                ?? $this->stringOrNull($metadata['source_file'] ?? null),
            similarity: $this->floatOrNull($row['similarity'] ?? null),
            lexicalScore: $this->floatOrNull($row['bm25_score'] ?? null),
            filedAt: $this->dateOrNull($row['created_at'] ?? null)
                ?? $this->dateOrNull($metadata['filed_at'] ?? null),
            addedBy: $this->stringOrNull($metadata['added_by'] ?? null),
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws MemPalaceUnavailable
     */
    private function drawerIdFrom(array $payload, string $tool): DrawerId
    {
        /** @var mixed $nested */
        $nested = $payload['drawer'] ?? null;

        $candidates = [
            $payload['drawer_id'] ?? null,
            $payload['id'] ?? null,
            \is_array($nested) ? ($nested['drawer_id'] ?? null) : null,
            \is_array($nested) ? ($nested['id'] ?? null) : null,
            \is_string($nested) ? $nested : null,
        ];

        foreach ($candidates as $candidate) {
            $id = $this->stringOrNull($candidate);
            if (null !== $id) {
                return new DrawerId($id);
            }
        }

        // Deliberately fatal. The content is in the palace by now, but without
        // an identifier there is no registry row, and a drawer the registry does
        // not know is a drawer the second filtering layer will always drop.
        // Better a visible error than content silently written into the void.
        throw MemPalaceUnavailable::malformed($tool, 'odpowiedź nie zawiera identyfikatora szuflady');
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            // A date we cannot read is worth less than no date: a wrong
            // timestamp would silently distort every time-filtered search.
            return null;
        }
    }
}
