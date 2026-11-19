# Boot 70, measured on PostgreSQL: the runtime SQL runs on the second engine

Part 27 planned the driver, and parts 30 to 34 built it with the PostgreSQL
fragments asserted as text because no server was reachable. A server is
installed now, so this round replaced that text with executed SQL: every module
database started empty, the real migrators created its schema, the
repositories wrote and read it, and the HTTP kernel answered requests.

## What moved

| Site | Was | Now |
|---|---|---|
| `BlogAuthorResolver` email lookup | `email = :email COLLATE NOCASE` | `noCaseEquals()` |
| `BlogAuthorResolver` active lookups and `UserRepository` writes | `is_active = 1`, `VALUES (..., 1, ...)` | `booleanLiteral()` |
| `UserRepository::findByEmail()` and `emailExists()` | `COLLATE NOCASE` | `noCaseEquals()` |
| `FormRepository::listForLocale()` | `ORDER BY title COLLATE NOCASE` | `noCase()` |
| `AuditMetric`, `BlogMetric`, `CommentsMetric`, `FormsMetric`, `MediaMetric`, `OutboxMetric`, `QueueMetric`, `UsersMetric` | `datetime('now', :interval)` | `shiftedTimestamp()` |
| `AuditLogRepository`, `SubmissionRepository`, `SqliteNotificationRepository`, `SpecRepository` | `rowid` tiebreaks | `insertionOrder()` |

`noCaseEquals()` keeps the SQLite form byte-identical by attaching the
collation to the parameter, while PostgreSQL lowers both sides.
`booleanLiteral()` answers `1` or `TRUE`, and is now what `booleanDefault()`
delegates to. The `User` entity also accepts the `t`/`f` text PostgreSQL
returns for a boolean column, next to SQLite's `1`/`0`.

## Measured

- PostgreSQL 18.7 with `pdo_pgsql`, thirteen module databases created empty.
  `php bin/cms migrate` exits 0 and creates every schema on the first run.
- `UserRepository`: insert, case-insensitive `findByEmail()`, `emailExists()`
  with mixed case, update to inactive, and delete all run without an error.
- Nine metric windows and the sessions window execute against the PostgreSQL
  schemas.
- Six ordering shapes, one for every replaced `rowid`, execute against
  `audit`, `forms`, `notifications` and `restpanel`.
- HTTP through `php -S`: `/` 200, `/blog` 200, `/sk/` 200, `/mark` 302, with
  no `SQLSTATE` in any body or server log.
- The same suite is green on both engines: 1516 tests on SQLite (one skip)
  and 1516 on PostgreSQL (two skips). Static analysis, style and mago are
  clean.

## The engine-aware suite

`DriverSeamTest` now restores the ambient `DB_DRIVER` instead of leaving it
unset, which is what made the rest of a PostgreSQL run fall into SQLite while
`DB_<NAME>` still held DSNs. The PostgreSQL measurement itself lives in
`tests/Postgres`: it owns `DB_DRIVER=pgsql`, refuses to run without every module
DSN, and asserts the migrated schemas, a boolean user round trip, all nine
metric windows and the six ordering shapes. The `postgres` CI job starts a
`postgres:16` service, creates the thirteen databases, installs the catalogue,
migrates, runs that suite with `--fail-on-skipped`, then walks the admin flow:
a real login with a CSRF token, the dashboard, and the outbox drain that
records the login's security event.

`AuthorMemberSinceTest` also pins the `TIMESTAMPTZ` shapes a reader gets back
(`+00`, `+01:00`, `-05`), so an offset is treated as the instant it names
rather than a second wall clock.

## Both engines, one suite

Every test that seeds a SQLite file now pins the engine through
`Tests\Support\SqliteDatabase`. The trait snapshots every `DB_*` variable,
removes the DSN-shaped values SQLite cannot read as paths, and sets
`DB_DRIVER=sqlite`; its restore puts the run's values back and is called both
at the start and at the end of `tearDown`, so a test that unsets or rewrites a
variable in its own cleanup cannot leave the next test on the wrong engine.
The ClickHouse status test and Blog's mark-escape test seed their own files
through the same `DB_<NAME>` overrides a deployment uses. Tests that boot the
locale layer or the admin dashboard build a temporary `config.db` (and the
questions fixture they render) with the module migrators. No test in the full
suite depends on a migrated `storage/data`.

That makes the whole catalogue suite engine-aware. The `postgres` job runs the
full suite with `--exclude-group postgres` next to the PostgreSQL-specific
suite. Both runs are 1516 tests, and the full suite takes about half a minute
on either engine.

**ClickHouse** stays on SQLite by design: its bridge is not part of this
port.
