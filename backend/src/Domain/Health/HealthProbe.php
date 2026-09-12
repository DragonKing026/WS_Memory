<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Port: one checkable system dependency.
 *
 * Monitoring another dependency (frontend, embedding server, mailer) must mean
 * adding a class implementing this interface — never editing the controller.
 * Implementations are collected by tag and injected as a list.
 */
interface HealthProbe
{
    /** Name shown in the response, e.g. "database". */
    public function name(): string;

    public function check(): DependencyStatus;
}
