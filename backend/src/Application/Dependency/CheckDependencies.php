<?php

declare(strict_types=1);

namespace App\Application\Dependency;

/**
 * "Go and find out where the dependencies stand."
 *
 * Carries nothing, and that is the point: there is exactly one thing to check and
 * no parameter that would change the answer. A field here — a package name, a
 * timestamp — would be a field the scheduler had to invent every six hours, and
 * two messages differing only in it would look like two different jobs.
 *
 * Asynchronous because it talks to PyPI over the internet. Nobody is waiting for
 * it, and a check that fails is meant to be retried later rather than to fail a
 * request.
 */
final readonly class CheckDependencies
{
}
