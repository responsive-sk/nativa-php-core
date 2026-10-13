<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Interfaces\HTTP;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Outbox\Application\Services\OutboxDrain;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\RunnerOutput;
use Modules\Scheduler\Scheduling\Schedule;
use Modules\Scheduler\Scheduling\ScheduleRunner;
use Modules\Scheduler\Scheduling\ScheduleRunResult;

/**
 * SchedulerServices — lazy cached service factories for the Scheduler module.
 *
 * The shipped timetable mirrors `outbox:process`: `outbox:drain` every five
 * minutes delivers pending outbox messages, `outbox:prune` daily deletes
 * processed rows older than seven days. Both entries are registered only
 * when the Outbox module is installed (`class_exists`, not a hard
 * dependency) — Scheduler stays a leaf, and on a tree without Outbox the
 * timetable is empty and `schedule:run` reports nothing due.
 *
 * `run()` is the exit-code seam `bin/cms schedule:run` drives: per-task
 * lines plus the summary go to $output, 1 returns on any failure else 0.
 */
final class SchedulerServices
{
    private static ?FileTaskMutex $mutex = null;

    /**
     * The shipped timetable. Callers add their own entries to the returned
     * Schedule — it is a fresh instance per call, never shared.
     */
    public static function schedule(): Schedule
    {
        $schedule = new Schedule();

        if (class_exists(OutboxDrain::class)) {
            $schedule->call(static function (): void {
                OutboxDrain::drain(100);
            })->everyFiveMinutes()->description('outbox:drain');
        }

        if (class_exists(OutboxServices::class)) {
            $schedule->call(static function (): void {
                OutboxServices::repository()->cleanProcessed('7 days');
            })->daily()->description('outbox:prune');
        }

        return $schedule;
    }

    public static function mutex(): FileTaskMutex
    {
        return self::$mutex ??= new FileTaskMutex(
            AppPaths::instance()->storage('cache/scheduler-mutexes/'),
        );
    }

    public static function runner(?Schedule $schedule = null, ?FileTaskMutex $mutex = null): ScheduleRunner
    {
        return new ScheduleRunner($schedule ?? self::schedule(), $mutex ?? self::mutex());
    }

    /**
     * Run the due tasks of $schedule at $now (default: wall time).
     *
     * @return int 1 when any due task failed, else 0
     */
    public static function run(
        Schedule $schedule,
        RunnerOutput $output,
        ?DateTimeInterface $now = null,
        ?FileTaskMutex $mutex = null,
    ): int {
        $result = self::runner($schedule, $mutex)->run($now ?? new DateTimeImmutable(), $output);

        return self::exitCode($result);
    }

    public static function exitCode(ScheduleRunResult $result): int
    {
        return $result->hasFailures() ? 1 : 0;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$mutex = null;
    }
}
