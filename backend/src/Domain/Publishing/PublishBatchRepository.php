<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Port: the journal of publications, and of what was undone.
 *
 * Deliberately has no delete. A batch is kept forever, `reverted` included
 * (integrity rule 7 in docs/02-model-danych.md): the point of the journal is to
 * answer "what left my machine, and where did it go" months later, and a journal
 * that shrinks when somebody undoes a mistake cannot answer it. A publication
 * nobody can account for afterwards is the thing that makes people switch
 * sending off — which costs far more than a table of old rows.
 */
interface PublishBatchRepository
{
    /**
     * Books a batch. Called inside the caller's transaction, always.
     *
     * "Always" is not advice: the batch row and the registry rows it accounts for
     * have to appear together or not at all. A batch claiming twelve drawers that
     * were rolled back is a revert button pointing at nothing.
     */
    public function record(PublishBatch $batch): void;

    public function find(string $id): ?PublishBatch;

    /**
     * Marks a batch undone, keeping the row.
     *
     * @throws \DomainException if the batch is unknown, or was not in a state that can be undone
     */
    public function markReverted(string $id, \DateTimeImmutable $at): void;
}
