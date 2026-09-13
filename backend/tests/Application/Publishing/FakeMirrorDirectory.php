<?php

declare(strict_types=1);

namespace App\Tests\Application\Publishing;

use App\Domain\Publishing\Mirror;
use App\Domain\Publishing\MirrorDirectory;

/**
 * Mappings as a literal list, so the landing rule needs no database.
 *
 * Keyed by owner as well as replica and wing, exactly as the real table is. That
 * is not fidelity for its own sake: one person confirming a mapping must not apply
 * to another person mining the same repository into a wing of the same name, and a
 * double keyed on the wing alone would let that bug pass every test.
 */
final class FakeMirrorDirectory implements MirrorDirectory
{
    /** @var array<string, Mirror> */
    private array $mirrors = [];

    public function given(Mirror $mirror): void
    {
        $this->mirrors[$this->keyOf($mirror->userId, $mirror->sourceReplica, $mirror->sourceWing)] = $mirror;
    }

    public function mirrorFor(string $userId, string $sourceReplica, string $sourceWing): ?Mirror
    {
        return $this->mirrors[$this->keyOf($userId, $sourceReplica, $sourceWing)] ?? null;
    }

    private function keyOf(string $userId, string $replica, string $wing): string
    {
        return $userId . "\0" . $replica . "\0" . $wing;
    }
}
