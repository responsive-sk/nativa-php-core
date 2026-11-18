# Boot 67, measured on SQLite: the migrations ask the dialect

Step 3 of the plan in part 27. No PostgreSQL server was reachable, so the
PostgreSQL fragments are asserted as text and the SQLite fragments are executed
against real files.

## The plan was off by five

The plan counted eight `DatabaseMigrator`s. The tree has thirteen:
`Audit`, `Blog`, `Comments`, `Forms`, `Locales`, `Media`, `Notifications`,
`Outbox`, `Questions`, `Queue`, `RestPanel`, `Settings` and `Users`. All
thirteen now build their schema through the dialect, and the installed copies
under `src/Modules` were reinstalled from the catalogue so the suite runs the
new code rather than the previous copy.

## What the dialect grew

The seven measured forms from step 2 were not enough for a schema. These were
added, each because a migrator needed it:

- `forPdo()` reads the driver off the handle, since a migrator receives only a
  `\PDO`.
- `timestampType()` answers `DATETIME` or `TIMESTAMPTZ`. PostgreSQL has no
  `DATETIME`, and the plan had not counted the column type among the differences.
- `booleanDefault()` answers `1`/`0` or `TRUE`/`FALSE`. PostgreSQL refuses an
  integer default on a `BOOLEAN` column.
- `columnNames()` reads `PRAGMA table_info` or `information_schema.columns`, so
  an idempotent `ALTER TABLE` probe is not SQLite SQL in the migrator.
- `immutableTable()` returns the statements that make a table append-only: two
  `RAISE(ABORT)` triggers on SQLite, a plpgsql function plus two triggers on
  PostgreSQL.

`insertOrIgnore()`, `autoIncrementColumn()` and `tableExists()` already existed.
`RestPanel`'s adoption of `restadmin.db` is `ATTACH` and a file rename, so it is
skipped on PostgreSQL explicitly rather than attempted and caught.

## The measurement that corrected the design

Step 2 left `insertOrIgnore()` returning `ON CONFLICT DO NOTHING` for both
engines, on the assumption that SQLite has accepted the clause since 3.24. It
has, for `INSERT ... VALUES`. It does not accept it after an `INSERT ... SELECT`:

```
SQLSTATE[HY000]: General error: 1 near "DO": syntax error
```

That was measured on SQLite 3.54.0 while the adoption test for `RestPanel`
failed with an empty table, because the failing statement was inside a
`catch (\PDOException)` that reported to a quiet output. Three call sites are
backfills of exactly that shape (`RestPanel` adoption, `Media` folder registry,
`Users` role merge), so the dialect now keeps SQLite's own `INSERT OR IGNORE`
prefix and reserves the clause for PostgreSQL. This also keeps the SQLite SQL
byte for byte what it was.

## What is tested

- `SqlDialectTest` pins the fragments for both drivers, reads the dialect from a
  live handle, reads columns from a live table, and executes the `INSERT ...
  SELECT` regression on SQLite.
- The thirteen reinstalled migrators run in the full suite: 1511 tests, 8657
  assertions, one skip, with `tests/Database` at 75 tests.
- The export gate discovers 941 tests.

## Still open

- **Runtime DDL.** `ViewCounter`, `RateLimiter` and `Scheduler` still create
  tables outside the migrators, with `AUTOINCREMENT` and `sqlite_master`
  probes. A PostgreSQL install would fail there before it reaches a migration.
- **Repository SQL.** `MediaFolderRepository`, `UserRepository` and
  `RoleRepository` still write `INSERT OR IGNORE`, and `Scheduler`,
  `RateLimiter` and the ClickHouse schema reader still ask `sqlite_master`.
- **Boolean predicates.** The column type is portable now, but application SQL
  such as `is_active = 1` is not valid against a PostgreSQL `BOOLEAN`.
- **Timestamps.** `TIMESTAMPTZ` returns a different text shape than SQLite's
  `DATETIME`, and no reader has been measured against it.
- **Step 5.** `FOR UPDATE SKIP LOCKED`, the SERIALIZABLE outbox drain and the
  `postgres:16` CI job are untouched; until that job exists, every PostgreSQL
  fragment above is asserted only as text.
