<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\KnowledgeFact;
use App\Domain\Memory\MemoryFragment;
use App\Domain\Memory\MemoryKind;
use App\Domain\Memory\MemoryQuery;
use App\Domain\Memory\MemoryStore;
use App\Domain\Memory\MemoryUnavailable;
use App\Domain\Memory\PalaceWing;

/**
 * A palace that records what it was asked, rather than a mock.
 *
 * The assertions these tests need are about the calls themselves — which wings
 * were searched, and whether any search went out without one. A mock would
 * verify that we called a method; this double lets the test read the traffic.
 */
final class InMemoryMemoryStore implements MemoryStore
{
    /** @var list<array{wing: string, query: string}> */
    public array $searches = [];

    /** @var list<array{wing: string, kind: string, content: string, addedBy: string}> */
    public array $writes = [];

    /** @var list<string> */
    public array $factQueries = [];

    /** @var list<array{drawer: string, content: string}> */
    public array $replacements = [];

    /** @var list<KnowledgeFact> */
    public array $facts = [];

    public bool $unavailable = false;

    /** @var array<string, array<int, MemoryFragment>> keyed by wing */
    private array $contents = [];

    /** @var array<string, MemoryFragment> keyed by drawer id */
    private array $drawers = [];

    /** @var list<KnowledgeFact> */
    private array $storedFacts = [];

    private int $counter = 0;

    public function search(PalaceWing $wing, MemoryQuery $query): array
    {
        $this->guard();
        $this->searches[] = ['wing' => $wing->value, 'query' => $query->text];

        return array_values($this->contents[$wing->value] ?? []);
    }

    public function fetch(DrawerId $drawer): ?MemoryFragment
    {
        $this->guard();

        return $this->drawers[$drawer->value] ?? null;
    }

    public function store(
        PalaceWing $wing,
        MemoryKind $kind,
        string $content,
        string $addedBy,
        ?string $sourceFile = null,
    ): DrawerId {
        $this->guard();
        $this->writes[] = [
            'wing' => $wing->value,
            'kind' => $kind->value,
            'content' => $content,
            'addedBy' => $addedBy,
        ];

        // The real palace merges identical content within a wing and answers with the
        // drawer it already holds. Reproduced here, because the interesting case in
        // MemoryService is exactly what happens on that second write.
        foreach ($this->drawers as $existing) {
            if ($existing->wing->equals($wing) && $existing->room === $kind->room() && $existing->content === $content) {
                return $existing->id;
            }
        }

        $drawer = new DrawerId(\sprintf('drawer_%s_%s_%d', $wing->value, $kind->room(), ++$this->counter));
        $this->drawers[$drawer->value] = new MemoryFragment($drawer, $wing, $kind->room(), $content);

        return $drawer;
    }

    public function replace(
        DrawerId $drawer,
        PalaceWing $wing,
        MemoryKind $kind,
        string $content,
        string $addedBy,
    ): DrawerId {
        $this->guard();

        if (!isset($this->drawers[$drawer->value])) {
            return $this->store($wing, $kind, $content, $addedBy);
        }

        $this->replacements[] = ['drawer' => $drawer->value, 'content' => $content];
        $this->drawers[$drawer->value] = new MemoryFragment($drawer, $wing, $kind->room(), $content);

        foreach ($this->contents[$wing->value] ?? [] as $index => $fragment) {
            if ($fragment->id->equals($drawer)) {
                $this->contents[$wing->value][$index] = $this->drawers[$drawer->value];
            }
        }

        return $drawer;
    }

    public function queryFacts(string $entity, ?string $direction = null): array
    {
        $this->guard();
        $this->factQueries[] = $entity;

        return array_values(array_filter(
            $this->storedFacts,
            static fn (KnowledgeFact $fact): bool => $fact->subject === $entity || $fact->object === $entity,
        ));
    }

    public function addFact(KnowledgeFact $fact): void
    {
        $this->guard();
        $this->facts[] = $fact;
        $this->storedFacts[] = $fact;
    }

    public function writeDiary(PalaceWing $wing, string $agentName, string $entry, ?string $topic = null): DrawerId
    {
        return $this->store($wing, MemoryKind::Diary, $entry, $agentName);
    }

    /**
     * Puts content into a wing as if the palace already held it.
     */
    public function given(string $wing, string $drawerId, string $content, float $similarity = 0.7): MemoryFragment
    {
        $palaceWing = new PalaceWing($wing);
        $fragment = new MemoryFragment(
            new DrawerId($drawerId),
            $palaceWing,
            'technical',
            $content,
            similarity: $similarity,
        );

        $this->contents[$wing][] = $fragment;
        $this->drawers[$drawerId] = $fragment;

        return $fragment;
    }

    public function givenFact(KnowledgeFact $fact): void
    {
        $this->storedFacts[] = $fact;
    }

    private function guard(): void
    {
        if ($this->unavailable) {
            throw new MemoryUnavailable('pamięć nie odpowiada (test)');
        }
    }
}
