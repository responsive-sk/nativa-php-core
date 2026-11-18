# Boot 65, measured on SQLite: the driver seam

This is steps 1 and 2 of the plan in part 27, and deliberately nothing beyond
them. No PostgreSQL server was reachable, so anything that needs one is still
open and is named at the end rather than guessed at.

## What changed

- **`DB_DRIVER` picks the engine.** Unset means `sqlite`, the path every
  deployment already runs. `pgsql` reads `DB_<NAME>` as a DSN body
  (`host=…;port=5432;dbname=users`), and a read replica is a `DB_<NAME>_READ`
  list of the same form. An unknown value is refused with the supported names
  before a connection is attempted; a missing `DB_<NAME>` under `pgsql` is
  refused with the variable named.
- **`DsnBuilder` is the one place a target becomes a DSN.** `sqlite:` plus the
  file path, `pgsql:` plus the body. Nothing else in the tree builds a DSN.
- **`SessionSettings` owns the session.** The four SQLite pragmas
  (`busy_timeout`, `foreign_keys`, `journal_mode`, `query_only`) moved here
  unchanged, including the refused-WAL log line. PostgreSQL's read-only handle
  gets `SET default_transaction_read_only = on`, which is `query_only`'s
  counterpart: the server refuses a misrouted write.
- **`SqlDialect` answers the seven measured forms.** `autoIncrementColumn`,
  `currentTimestamp`, `noCase`, `tableExists`, `returning`, `insertOrIgnore`
  and `quoteIdentifier`. `ON CONFLICT DO NOTHING` is the one pair that
  collapsed, because SQLite has understood it since 3.24.
- **The contract grew three methods:** `driverName()`, `quoteIdentifier()` and
  `supportsReturning()`. `PdoConnection` answers them from the handle it owns
  (`supportsReturning` compares SQLite's actual version with 3.35);
  `ReadWriteConnection` delegates to its write side, so the answer is the
  primary's, not a replica's.

## What is tested

`tests/Database` is 64 tests and 145 assertions, including the new
`SqlDialectTest` and `DriverSeamTest`: the DSN text for both drivers, the two
refusals (unknown driver, missing `DB_<NAME>`), quoting with an embedded quote,
each dialect form, and the contract's answers on a real SQLite handle. The
pragmas are asserted by the existing routing tests, which open real files
through the manager.

## Still open, in the plan's order

- **Step 3, the migrations.** Eight `DatabaseMigrator`s still write SQLite DDL
  (`AUTOINCREMENT`, `PRAGMA table_info`, `INSERT OR IGNORE`). The dialect can
  now express both, but no migrator uses it yet.
- **Step 4, the errors.** A unique violation is 23505 on PostgreSQL and
  23000/19 on SQLite, a foreign key violation 23503 against 787. Nothing maps
  them to domain failures yet.
- **Step 5, concurrency.** PostgreSQL defaults to READ COMMITTED, which is
  weaker than what the Scheduler and the Outbox assume; `FOR UPDATE SKIP
  LOCKED` and the SERIALIZABLE outbox drain are not written.
- **The CI job with a `postgres:16` service** that fails on skips. Without it,
  every PostgreSQL-specific branch is unmeasured in CI, which is exactly the
  state the retired extract-check was in.
- **`lastInsertId()` still returns `string`**, and `afterCommit()` /
  `afterRollback()` are still absent. Part 27 lists them with the driver, not
  before it.
