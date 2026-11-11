# Boot 64, planned: a Postgres driver behind the same contract

This is a plan, not a round. Nothing below was measured against a running
PostgreSQL server, because none was reachable while it was written. What *is*
measured is the SQLite surface the driver has to replace, and that measurement
is what orders the work: it is the only number here that can be wrong in a way
the first day of the round will not notice.

The contract is already driver-neutral, and that is the round's whole premise.
`ConnectionInterface` says `query`/`prepare`/`execute`, `ReadWriteConnection`
routes, `UnitOfWork` nests savepoints, `TransactionRetry` retries a conflict.
None of that names a driver. What names SQLite is the DSN in
`DatabaseConnectionManager::openPdo()`, the `PRAGMA` lines beside it, and the
SQL in 43 files.

## What the previous rounds already settled for the driver

- **The seam.** A repository is typed against `ConnectionInterface`
  (`27-db-connection-contract` part 26), so a driver is one more implementation
  of nine methods, not a rewrite of 61 files that typed `\PDO`.
- **Routing.** `ReadWriteConnection` and `FailoverStatement` are in place, so a
  Postgres primary with a read replica is `DB_<NAME>_READ` plus a DSN, not a
  second routing layer.
- **Transactions and savepoints.** `UnitOfWork` writes `SAVEPOINT uow_2`,
  `ROLLBACK TO SAVEPOINT`, `RELEASE SAVEPOINT`, and all three are PostgreSQL
  syntax as well.
- **The retry.** `transaction(attempts, backoff)` and
  `TransactionConflictException::detect()` already read SQLSTATE 40001 and
  40P01, so the driver does not have to grow a retry loop. It has to classify
  correctly, which `detect()` already does for the two states that matter.

## The surface that has to move

Measured with `grep` over `modules/`, `src/Seo`, `src/Shell`, `src/Themes`,
`src/View`, `src/Discovery` and `src/Kernel`, tests excluded:

| What | Lines | Where it becomes a problem |
|---|---:|---|
| `PRAGMA` | 15 | `busy_timeout`, `foreign_keys`, `journal_mode`, `query_only` have no PostgreSQL equivalent; each is a different statement |
| `INSERT OR IGNORE` | 12 | `ON CONFLICT DO NOTHING`, and the two swallow a unique violation the caller may need |
| `AUTOINCREMENT` | 5 | `GENERATED ALWAYS AS IDENTITY` in the migrations that create the tables |
| `sqlite_master` | 4 | `information_schema` or `pg_catalog`: Scheduler's table check, the ClickHouse schema reader, `RateLimiter` |
| `COLLATE NOCASE` | 4 | `LOWER()` with an expression index, or `citext` |
| `RETURNING` | 2 | already written *for* SQLite older than 3.35; PostgreSQL always has it, so this one gets simpler |
| `datetime('now')` | 1 | `now()`, and the TEXT timestamps around it become `timestamptz` |

That is 43 lines in 43 files that carry SQL, and the other SQL is portable:
`SELECT`, `INSERT`, `UPDATE`, `DELETE`, parameters, `LIMIT`. The ClickHouse
bridge uses SQLite as its engine on purpose and is not part of this at all.

## The five steps, in this order

Each step is shippable on SQLite alone, so a failure at step 4 does not hold
step 1 hostage.

1. **The DSN and the session.** `DB_DRIVER` next to `DB_<NAME>`, a DSN builder
   per driver, and the session settings that replace the four PRAGMAs
   (`SET default_transaction_read_only` on a replica is `query_only`'s
   counterpart). Keep the SQLite path byte-for-byte, including `busy_timeout`
   and the refused-WAL log line, because that is what every existing deployment
   runs.
2. **The dialect.** `quoteIdentifier()` on the contract, and a small dialect
   object for the seven forms above. This is the step that earns `driverName()`
   on the contract, which the contract series deliberately left out: without a
   driver to ask, it would have been a name for one implementation.
3. **The migrations.** Eight `DatabaseMigrator`s, one per database, each
   keeping its `MigratorInterface` shape and its idempotent seeding. DDL is
   where the two engines differ most and where a mistake is hardest to undo, so
   it comes after the dialect exists to express it.
4. **The errors.** A unique violation is 23505 on PostgreSQL and 23000/19 on
   SQLite; a foreign key violation is 23503 against 787. `INSERT OR IGNORE`
   hides both today. The driver maps them to domain failures, and the retry
   keeps 40001/40P01 only.
5. **Concurrency.** PostgreSQL's default is READ COMMITTED, which is weaker
   than what the Scheduler and the Outbox assume when they claim a row.
   `FOR UPDATE SKIP LOCKED` is the portable claim, and SERIALIZABLE for the
   outbox drain needs the retry that already exists.

## What the driver must not change

- **Kernel imports.** A kernel file still may not import a module
  (`make mago-guard`). A Postgres driver belongs under
  `src/Modules/Database/Infrastructure`, not in a new top-level namespace.
- **No Composer package.** `pdo_pgsql` is an extension, so the runtime keeps
  its zero-package rule.
- **The measured shape.** A driver that adds types without removing any is the
  thing `docs/kernel-shape.md` exists to catch; `MAX_APPROVED` is 28 concrete
  and 8 interface in Core, and this plan adds nothing there.

## How it gets tested

- **Both drivers, one suite.** The contract tests construct a `PdoConnection`
  around a `\PDO`, so a second DSN runs the same 43 SQL-bearing files. A test
  that skips on PostgreSQL is a gap, not a pass, and the CI job should fail on
  skips the way the catalogue job does.
- **CI.** One more job with a `postgres:16` service, running the same suite
  with `DB_DRIVER=pgsql`. SQLite stays the default so a clone still boots with
  no server.
- **The migrations twice.** Run them against a fresh database and against a
  database that already has the SQLite-era rows, because the seeding is
  `INSERT OR IGNORE` and re-running it is the documented behaviour.

## Still open after this plan

- **Connection pooling.** PHP-FPM's process model makes a pool a service in
  front of the database (pgbouncer), not a kernel feature. Named and out of
  scope.
- **A transaction across two databases.** `users.db`, `audit.db` and
  `outbox.db` are separate files and no transaction spans them today; on one
  PostgreSQL server they could, and that changes a documented invariant
  (`docs/security-patterns.md`). Not a driver decision.
- **`lastInsertId()` on multi-row inserts.** It returns one id; PostgreSQL's
  `RETURNING` returns all of them, and no call site needs that yet.
