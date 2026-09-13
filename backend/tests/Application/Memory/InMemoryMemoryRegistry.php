<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\EntryFacts;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryWrite;
use App\Domain\Memory\SourceBinding;
use App\Domain\Space\SpaceId;

/**
 * The registry without a database — including its transaction.
 *
 * The transaction is the interesting part to fake. `failOnRegister` lets a test
 * make bookkeeping fail after the palace has already accepted a write, which is
 * the one ordering where the two stores can disagree; without that lever the
 * rollback rule would be asserted by reading the code rather than by running it.
 */
final class InMemoryMemoryRegistry implements MemoryRegistry
{
    /** @var array<string, MemoryWrite> */
    public array $rows = [];

    public bool $failOnRegister = false;

    public int $transactions = 0;

    public function register(MemoryWrite $write): void
    {
        if ($this->failOnRegister) {
            throw new \RuntimeException('registry write failed (test)');
        }

        $this->rows[$write->drawer->value] = $write;
    }

    public function spacesFor(array $ids): array
    {
        $spaces = [];
        foreach ($ids as $id) {
            if (isset($this->rows[$id->value])) {
                $spaces[$id->value] = $this->rows[$id->value]->space;
            }
        }

        return $spaces;
    }

    public function describe(array $ids): array
    {
        $facts = [];
        foreach ($ids as $id) {
            $row = $this->rows[$id->value] ?? null;
            if (null === $row) {
                continue;
            }

            $facts[$id->value] = new EntryFacts(
                kind: $row->kind,
                title: $row->title,
                // The registry row records a token only for an agent, exactly as
                // the real table does.
                byAi: null !== $row->author->agentTokenId,
                // Verification is a property of a document, and the fake holds no
                // documents — a test that needs a verified hit builds the hit.
                verified: false,
                documentSlug: null,
            );
        }

        return $facts;
    }

    public function spaceFor(DrawerId $id): ?SpaceId
    {
        return $this->rows[$id->value]->space ?? null;
    }

    public function drawerForDocument(string $documentId): ?DrawerId
    {
        foreach ($this->rows as $id => $row) {
            if ($documentId === $row->documentId) {
                return new DrawerId($id);
            }
        }

        return null;
    }

    public function rebind(DrawerId $from, DrawerId $to): void
    {
        if (!isset($this->rows[$from->value])) {
            throw new \DomainException('Rejestr nie zna tej szuflady.');
        }

        $this->rows[$to->value] = $this->rows[$from->value];
        unset($this->rows[$from->value]);
    }

    public function bindingForSource(string $sourceReplica, string $sourceDrawerId): ?SourceBinding
    {
        foreach ($this->rows as $id => $row) {
            if ($row->sourceReplica === $sourceReplica && $row->sourceDrawerId === $sourceDrawerId) {
                return new SourceBinding(new DrawerId($id), $row->space);
            }
        }

        return null;
    }

    public function drawerWithContent(SpaceId $space, string $contentHash): ?DrawerId
    {
        foreach ($this->rows as $id => $row) {
            if ($row->space->value === $space->value && $row->contentHash === $contentHash) {
                return new DrawerId($id);
            }
        }

        return null;
    }

    public function refresh(MemoryWrite $write): void
    {
        if ($this->failOnRegister) {
            throw new \RuntimeException('registry write failed (test)');
        }

        if (!isset($this->rows[$write->drawer->value])) {
            throw new \DomainException('Rejestr nie zna tej szuflady.');
        }

        $this->rows[$write->drawer->value] = $write;
    }

    public function drawersInBatch(string $batchId): array
    {
        $drawers = [];
        foreach ($this->rows as $id => $row) {
            if ($row->publishBatchId === $batchId) {
                $drawers[] = new DrawerId($id);
            }
        }

        return $drawers;
    }

    public function forget(array $drawers): int
    {
        $gone = 0;
        foreach ($drawers as $drawer) {
            if (isset($this->rows[$drawer->value])) {
                unset($this->rows[$drawer->value]);
                ++$gone;
            }
        }

        return $gone;
    }

    public function countsFor(array $spaces): array
    {
        $counts = [];
        foreach ($spaces as $space) {
            $counts[$space->value] = \count(array_filter(
                $this->rows,
                static fn (MemoryWrite $row): bool => $row->space->value === $space->value,
            ));
        }

        return $counts;
    }

    public function transactional(\Closure $work): mixed
    {
        ++$this->transactions;
        $before = $this->rows;

        try {
            return $work();
        } catch (\Throwable $e) {
            $this->rows = $before;

            throw $e;
        }
    }

    /**
     * Pretends a drawer was filed by someone else, into the given space.
     */
    public function givenRow(MemoryWrite $write): void
    {
        $this->rows[$write->drawer->value] = $write;
    }
}
