<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Why an update order was refused, as a fact rather than as a status code.
 *
 * The domain has no opinion about HTTP — the REST surface maps these to 404, 409,
 * 422 and 503, and the MCP gateway (or a console command) is free to map them to
 * something else. Kept apart so the mapping is in one readable place instead of
 * spread over the constructors of an exception hierarchy.
 *
 * Both target problems carry the same code at the edge, and they are still separate
 * cases here: one is "that is not a version", the other is "that version is not one
 * of the releases we know about". The sentences a reader gets are different, and so
 * is what they should do next.
 */
enum UpdateRefusal
{
    case UnknownDependency;
    case MalformedTarget;
    case TargetNotInCatalog;
    case AlreadyInFlight;
    case UpdaterSilent;
}
