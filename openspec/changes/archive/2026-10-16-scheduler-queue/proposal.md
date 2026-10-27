> ARCHIVED 2026-10-16 — implemented. Scheduler core, `bin/cms schedule:run`
> with the Outbox timetable, the SQLite queue and the admin pages all shipped
> (commits `bcff023`, `98667a5`, `a7a3a6b`, `0c7d967`); tasks 5–7, which were
> left unchecked after the queue merge, were verified against the code before
> this archive — migrator (both tables, idempotent), `DatabaseQueue`
> (`BEGIN IMMEDIATE` + `reserved_at` guard, failed-job overflow),
> `HandlerRegistry` (unknown handler fails the job). The proposal below is the
> plan as written; do not edit, history only.

# Proposal: Scheduler + database queue (Marko adoption, Phase B)

Branch: feature/scheduler-queue
Created: 2026-10-14

## Original Request

ok > ktore marko packages by sa nam dali adoptovat ? aif plan

## Settings

- Testing: yes
- Logging: verbose
- Docs: yes  # mandatory docs checkpoint at completion, routed through aif-docs

## Goal

Port Marko's `scheduler` and `queue-database` packages as `modules/Scheduler/`
and `modules/Queue/` in plain PHP + SQLite, with zero `marko/*` dependencies,
and wire them into the tree: a plain-PHP `bin/cms schedule:run` branch beside
`cmdMigrate`, plus unification with the existing Outbox (its drain and prune
become scheduled timetable entries instead of standalone operator knowledge).

## Non-goals (deliberate cuts)

- `schedule:work` daemon loop — the deploy is `php -S`/FTP/rsync with no
  supervisor; a one-shot `schedule:run` driven by system cron is the whole
  story. Document the cut, do not build the daemon.
- PSR Clock, QueryBuilder, timezone config, `marko/core` Output — replaced by
  `DateTimeImmutable`, hand-written PDO SQL, UTC + `CURRENT_TIMESTAMP` (tree
  convention) and the existing `CliOutput`.
- `SKIP LOCKED` reservation — SQLite has none; reservation uses
  `BEGIN IMMEDIATE` + a `reserved_at` guard with an affected-rows check.
- Generic job serialization framework — payloads are JSON arrays with a
  `handler` key naming a registered callable (see Decisions).

## Constraints

- Zero-dependency runtime: no `composer require marko/*` (every Marko package
  pulls `marko/core` and breaks the `dist/` claim — verified in the adoption
  proposal). Port, do not depend.
- Module conventions: `modules/<Name>/` catalogue + `src/Modules/<Name>/`
  installed slice, kept in sync (`diff -rq`); `use Modules\<X>\` auto-wires
  the dependency closure (`module:show` must list Queue under Scheduler only
  if Scheduler really imports it — keep Scheduler queue-agnostic, wire the
  timetable in the composition, not in the module).
- SQLite schema conventions: `VARCHAR(32)` ids (`bin2hex(random_bytes(16))`),
  `CURRENT_TIMESTAMP` defaults, a `*DatabaseMigrator` with
  `run(CliOutput | OutputInterface)`, module-local `jobs.db`.
- Cron state: no daemon means `schedule:run` relies on system cron hitting it
  every minute; overlapping runs are prevented per-task by `FileTaskMutex`
  under `storage/cache/scheduler-mutexes/` (gitignored via `storage/cache/*`).
- Exit codes: `schedule:run` exits 1 when any due task failed (Marko parity),
  0 otherwise — a cron entry can notice a poison task.
- The existing in-request `OutboxDrain` stays untouched (it is the delivery
  path on traffic-less deploys too); `schedule:run` ADDS a periodic drain +
  prune pass, it does not replace the drain.

## Decisions

- Timetable lives in code: `SchedulerServices::schedule(): Schedule` builds
  the timetable; default entries are `outbox:drain` (every 5 minutes,
  `OutboxDrain::drain(100)`) and `outbox:prune` (daily, delete processed
  older than 7 days — same interval as `ProcessOutboxCommand`). No cron-file
  format in v1; a `storage/` cron config is a later change, not this one.
- Queue payload: `['handler' => '<name>', 'args' => [...]]`; handlers register
  in a `HandlerRegistry` (`name => callable`), unknown handler = failed job,
  never a fatal. v1 ships exactly one handler shape: closures are NOT
  serializable — timetable closures run in-process, queued jobs reference
  named handlers only.
- Attempts semantics follow Marko: the `attempts` column counts reservations
  started (including worker deaths); reaching `maxAttempts` (default 3, per
  job overridable) moves the job to `failed_jobs` instead of re-queueing.
- `withoutOverlapping()` + `FileTaskMutex` with expiry: a crashed holder's
  lock dies with the process (flock), a hung holder's expires (timestamp in
  file, contender unlinks past expiry). Same-host only — documented, matches
  the deploy (single host, shared hosting).
- `schedule:run` output goes through `CliOutput` (the `bin/cms` convention),
  one line per task (`ok`/`failed (...)/skipped (still running)`) plus a
  summary count line; verbose logging means each reservation, release and
  mutex decision is logged at INFO during implementation, then trimmed to
  the per-task lines before the commit.

## Open questions

- None blocking. The `jobs.db` filename follows the module-local convention
  (`users.db`, `locales.db`); if a DBA objects, it renames with the migrator.
