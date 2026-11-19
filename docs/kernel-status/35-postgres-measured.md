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
- The SQLite suite is unchanged and green: 1512 tests, and the export gate
  still discovers 947. Static analysis, style and mago are clean.

## Still open

- **The whole PHPUnit suite under `DB_DRIVER=pgsql` is not green yet.** Fifteen
  tests seed SQLite files through `DB_<NAME>` without pinning `DB_DRIVER`, and
  `DriverSeamTest` unsets it mid-run, so the process half-switches engines
  depending on test order. A PostgreSQL CI job needs those tests to state
  their engine, or a separate suite that owns the PostgreSQL setup.
- **The `postgres:16` job** is therefore still not added.
- **The `TIMESTAMPTZ` text shape** a reader gets back (timezone offset
  included) is not asserted anywhere yet.
- **ClickHouse** stays on SQLite by design: its bridge is not part of this
  port.
