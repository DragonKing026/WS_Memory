<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Port: where the result of the last check is kept.
 *
 * A check costs a call to PyPI and one to the palace, and the panel is opened far
 * more often than every six hours — so the answer has to outlive the request that
 * produced it. It also has to outlive a FAILED request, which is the reason this
 * is a port with a read on it rather than a plain insert: preserving the previous
 * `latest` across an outage is impossible without first knowing what it was.
 */
interface DependencyStateStore
{
    /**
     * @return DependencyRecord|null null when nothing has ever been checked
     */
    public function find(string $name): ?DependencyRecord;

    /**
     * Writes the record, replacing whatever was there for that name.
     *
     * One row per dependency, not a history: this table answers "what is the
     * situation now". The trail of who asked for what and when belongs in
     * `ws.audit_log`, which is append-only and already exists.
     */
    public function save(DependencyRecord $record): void;
}
