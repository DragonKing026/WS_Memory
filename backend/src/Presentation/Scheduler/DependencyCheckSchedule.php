<?php

declare(strict_types=1);

namespace App\Presentation\Scheduler;

use App\Application\Dependency\CheckDependencies;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Every six hours, ask where the palace stands.
 *
 * Six hours rather than a cron entry at a fixed time, and rather than every hour.
 * A release is noticed within a working day either way, which is all the urgency
 * there is — the pin is deliberate and no update is ever automatic (TODO-015). An
 * hourly check would be twenty-four requests a day to PyPI to learn something that
 * changes a few times a year.
 *
 * **Stateful, and that is not decoration.** The worker is restarted every hour by
 * `--time-limit=3600`, and it is restarted whenever the stack is brought up. A
 * stateless schedule starts counting from zero on each restart, so a system
 * restarted more often than every six hours would never check at all — the failure
 * being that nothing happens and nothing says so, which is precisely the kind of
 * silence this task exists to remove. The cache remembers when the last run was.
 *
 * `processOnlyLastMissedRun` because these messages are not cumulative: a machine
 * off for two days owes us one check, not eight.
 */
#[AsSchedule('dependencies')]
final readonly class DependencyCheckSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('6 hours', new CheckDependencies()));
    }
}
