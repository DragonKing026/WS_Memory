<?php

declare(strict_types=1);

namespace App\Application\Dependency;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the scheduled check.
 *
 * Thin on purpose — the service already stores its own result and already keeps a
 * failed check from erasing a good one, so there is nothing for a handler to add
 * except the decision about retries.
 *
 * **It never lets the check throw.** DependencyCheckService turns every failure it
 * expects into a stored `check_problem`, so an exception escaping here would be
 * something genuinely unforeseen — and Messenger would answer it by retrying three
 * times and then parking the message in `failed`, where nobody looks. The next run
 * is in six hours and will try again anyway; that is a better retry policy than one
 * that ends in a dead-letter queue. So the outcome is logged and the message is
 * acknowledged.
 */
#[AsMessageHandler]
final readonly class CheckDependenciesHandler
{
    public function __construct(
        private DependencyCheckService $dependencies,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckDependencies $message): void
    {
        try {
            $status = $this->dependencies->check();
        } catch (\Throwable $e) {
            $this->logger->error('Cykliczne sprawdzenie zależności nie doszło do skutku.', [
                'exception' => $e,
            ]);

            return;
        }

        if ($status->record->updateAvailable()) {
            // Notice, not info: this is the whole reason the task exists, and it
            // should be findable in the log without anybody raising the level.
            $this->logger->notice('Jest nowsza wersja zależności.', [
                'name' => $status->name(),
                'installed' => (string) ($status->record->installed ?? '?'),
                'latest' => (string) ($status->record->latest ?? '?'),
            ]);
        }
    }
}
