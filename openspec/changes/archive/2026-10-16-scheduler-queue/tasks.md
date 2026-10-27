# Scheduler + database queue — tasks

> Archived 2026-10-16. Tasks 5–7 were left unchecked when the queue merge
> landed; before archiving, each was verified against the shipped code —
> `QueueDatabaseMigrator` (`jobs` + `failed_jobs`, `CREATE TABLE IF NOT
> EXISTS`), `DatabaseQueue` (`BEGIN IMMEDIATE` reservation with the
> `reserved_at IS NULL` guard, `release`/`delete`, failed-job overflow) and
> `HandlerRegistry` (`UnknownHandlerException` → failed job, never fatal) —
> together with `QueueDatabaseMigratorTest` and `DatabaseQueueTest`
> (`pushReserveDeleteRoundTrip`, `failingHandlerRetriesToMaxThenLandsInFailedJobs`,
> `secondReserveRefusedWhileHeld`, `delayedJobInvisibleUntilAvailable`,
> `unknownHandlerFailsTheJobNeverFatals`, `corruptPayloadParkedWithoutBlockingTheRest`).

Conventions for every task: catalogue first (`modules/<Name>/`), then sync
to `src/Modules/<Name>/` and confirm with `diff -rq`; module tests live
beside the code (`modules/<Name>/tests/`, skip when not installed);
LOGGING REQUIREMENTS are per-task (verbose during development, trimmed to
the committed lines before the checkpoint commit).

## Phase 1: Scheduler core (`modules/Scheduler/`)

- [x] Task 1: `CronExpression` + exceptions
  - Port `matches(string $expression, DateTimeInterface $time): bool`
    (5-field cron) + `InvalidCronExpressionException` /
    `SchedulerException`. No `marko/*` imports.
  - Tests: matrix over minutes/hours/dow/month (incl. `*/5`, ranges, lists,
    invalid expression throws). Files:
    `modules/Scheduler/Scheduling/CronExpression.php`,
    `modules/Scheduler/Scheduling/Exceptions/*.php`,
    `modules/Scheduler/tests/CronExpressionTest.php`.
  - LOGGING REQUIREMENTS: none (pure function) — PHPUnit output is the log.
- [x] Task 2: `ScheduledTask` fluent API + `Schedule`
  - Port `everyMinute|Five|Ten|Fifteen|Thirty|hourly|daily|weekly|monthly`,
    `cron()`, `description()`, `withoutOverlapping($minutes)`,
    `isDue()`, `run()`, `mutexName()`; `Schedule::call()` + `dueTasksAt()`.
  - Tests: fluents set expressions, due-filtering at fixed times, closure
    return value bubbles. Files: `modules/Scheduler/Scheduling/*.php`,
    `modules/Scheduler/tests/ScheduleTest.php`.
  - LOGGING REQUIREMENTS: none (pure) — PHPUnit output is the log.
- [x] Task 3: `FileTaskMutex` under `storage/cache/scheduler-mutexes/`
  - Port `acquire()`/`release()`/`exists()` on non-blocking `flock()` +
    expiry timestamp; same-host documented; `DateTimeImmutable` instead of
    PSR Clock.
  - Tests: acquire→held→release cycle, second contender refused while held,
    expiry lets a contender through, crashed-holder simulation (close handle
    without release). Files: `modules/Scheduler/Scheduling/FileTaskMutex.php`,
    `modules/Scheduler/tests/FileTaskMutexTest.php`.
  - LOGGING REQUIREMENTS: INFO per acquire/release/expiry decision during
    development; committed code logs nothing (caller reports).
- [x] Task 4: `ScheduleRunner` + `ScheduleRunResult`
  - Port due-task loop: executed/failed/skipped counts, failing task does
    not stop the rest, overlap-protected tasks without description throw,
    mutex skip counted. Output via a small `RunnerOutput` interface the CLI
    adapts (NOT `marko/core` Output).
  - Tests: mixed due/failing/overlapping timetable asserts counts; failure
    in task 1 still runs task 2. Files:
    `modules/Scheduler/Scheduling/ScheduleRunner.php`,
    `modules/Scheduler/tests/ScheduleRunnerTest.php`.
  - LOGGING REQUIREMENTS: per-task ok/failed/skipped lines stay committed.

<!-- Commit checkpoint: tasks 1-4 → `feat(scheduler): cron schedule core with file mutex` -->

## Phase 2: Queue (`modules/Queue/`)

- [x] Task 5: Schema + migrator (`jobs`, `failed_jobs` in `jobs.db`)
  - `SchedulerDatabaseMigrator`? No — `QueueDatabaseMigrator` with
    `run(CliOutput | OutputInterface)`: `jobs(id, queue, handler, payload,
    attempts, max_attempts, reserved_at, available_at, created_at)`,
    `failed_jobs(id, queue, handler, payload, attempts, failed_at, error)`.
    Tree id/timestamp conventions.
  - Tests: migrator creates both tables on a throwaway SQLite file. Files:
    `modules/Queue/Infrastructure/Persistence/Migrations/QueueDatabaseMigrator.php`,
    `modules/Queue/tests/QueueDatabaseMigratorTest.php`.
  - LOGGING REQUIREMENTS: migrator `writeln` lines (existing convention).
- [x] Task 6: `DatabaseQueue` push/pop/release + `failed_jobs` overflow
  - `push(handler, args, queue, maxAttempts)`, `pop()` reserving under
    `BEGIN IMMEDIATE` with the `reserved_at` guard (no `SKIP LOCKED` on
    SQLite — document the deviation), `release()`/`delete()`, attempts
    column authoritative, overflow to `failed_jobs` with the error message.
  - Tests (throwaway SQLite): push→pop→delete round-trip, failed handler
    retries to maxAttempts then lands in `failed_jobs` with the error,
    reservation guard (second pop skips the reserved row). Files:
    `modules/Queue/Application/Services/DatabaseQueue.php`,
    `modules/Queue/tests/DatabaseQueueTest.php`.
  - LOGGING REQUIREMENTS: INFO per push/reserve/release/failover during
    development; committed code quiet (the CLI reports).
- [x] Task 7: `HandlerRegistry` (name → callable)
  - Unknown handler resolves to a failed job, never a fatal. Tests:
    registered handler runs with args, unknown handler fails the job.
    Files: `modules/Queue/Application/Services/HandlerRegistry.php`,
    covered in `DatabaseQueueTest.php` (no new file).
  - LOGGING REQUIREMENTS: none — assertions only.

<!-- Commit checkpoint: tasks 5-7 → `feat(queue): sqlite job queue with failed-job store` -->

## Phase 3: Wiring (`bin/cms schedule:run` + Outbox timetable)

- [x] Task 8: `bin/cms schedule:run` (plain-PHP branch beside `cmdMigrate`)
  - `cmdScheduleRun()`: build timetable via `SchedulerServices::schedule()`,
    run due tasks at `now`, print per-task lines + summary, exit 1 on any
    failure else 0. Timetable defaults: `outbox:drain` every 5 min,
    `outbox:prune` daily (7-day interval, mirroring `ProcessOutboxCommand`).
  - Tests: exit 0 on clean timetable, exit 1 when a due task throws
    (drive `ScheduleRunner` through a test timetable; the CLI branch itself
    is thin). Files: `bin/cms`, `modules/Scheduler/Interfaces/HTTP/SchedulerServices.php`,
    `modules/Scheduler/tests/ScheduleRunCommandTest.php`.
  - LOGGING REQUIREMENTS: per-task + summary lines stay committed (this IS
    the operator interface); verbose reservation internals trimmed here.
- [x] Task 9: Gates + docs checkpoint
  - Full gates (`phpunit`, `phpstan`, `rector-dry`, `cs-fix-dry`) exit 0;
    catalogue/installed `diff -rq` clean for Scheduler + Queue;
    `module:show Scheduler` / `module:show Queue` closures sane;
    `module:add/remove` round-trip (hash identical before/after);
    `schedule:run` live against system cron cadence (two consecutive runs:
    second run skips nothing unexpected); docs checkpoint via aif-docs
    (new modules get their usage notes where module docs live).
  - LOGGING REQUIREMENTS: verbose — log each gate's exit code.
  - Files: (fixes wherever the gates point).

<!-- Commit checkpoint: tasks 8-9 → `feat(scheduler): schedule:run with outbox timetable` -->
