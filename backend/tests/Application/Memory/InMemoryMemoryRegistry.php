<?php

declare(strict_types=1);

namespace App\Tests\Application\Memory;

use App\Domain\Memory\DrawerId;
use App\Domain\Memory\MemoryRegistry;
use App\Domain\Memory\MemoryWrite;
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
