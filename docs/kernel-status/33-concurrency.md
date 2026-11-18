# Boot 68, measured on SQLite: the claim and the drain name their engine

Step 5 of the plan in part 27. No PostgreSQL server was reachable, so the
PostgreSQL statements are asserted as text and the SQLite paths are executed by
the existing queue and outbox suites.

## The two places that assumed SQLite

- `DatabaseQueue::reserve()` opened `BEGIN IMMEDIATE` by hand and relied on
  SQLite's single writer for the guarded update. On PostgreSQL that statement
  is a syntax error, and a plain `BEGIN` would let two claimants read the same
  row before either update.
- `OutboxDrain` read pending rows and updated them one by one with no
  transaction, which is safe on one SQLite writer and not on a database where
  two readers can select the same pending row.

## What changed

The dialect gained three statements:

| method | SQLite | PostgreSQL |
|---|---|---|
| `beginWrite()` | `BEGIN IMMEDIATE` | `BEGIN` |
| `beginSerializable()` | `BEGIN IMMEDIATE` | `BEGIN ISOLATION LEVEL SERIALIZABLE` |
| `skipLocked()` | empty | ` FOR UPDATE SKIP LOCKED` |

`DatabaseQueue` builds the dialect from the connection it was given, opens
`beginWrite()` before the select, and appends `skipLocked()` to both finding
queries. The guarded `UPDATE ... WHERE id = ? AND reserved_at IS NULL` stays in
both drivers, so the second contender still gets zero rows rather than the same
job.

`SqliteOutboxRepository::serializable()` opens `beginSerializable()` and retries
a `TransactionConflictException` up to three times with the same jittered
backoff as `TransactionRetry`, which cannot be reused directly: it begins
through the contract, and the contract has no isolation parameter. The same
backoff is restated rather than duplicated wholesale, and that is the one piece
of this round that a reviewer should weigh.

`OutboxProcessor::process()` runs its whole batch inside that transaction. A
handler failure is already caught per message and written as `failed`, so the
transaction commits; only a serialization conflict aborts it.

## What is tested

- `SqlDialectTest` pins the three statements for both drivers.
- The queue and outbox suites run unchanged and pass: 46 tests, 239 assertions.
  The second-reserve test still proves the guard refuses a held row.
- The full suite is 1512 tests, 8664 assertions, one skip. `tests/Database` is
  76 tests and the export gate discovers 946.

## Still open

- **The `postgres:16` CI job.** Until it exists and fails on skips, the
  PostgreSQL statements here are asserted only as text. It cannot be green
  before the runtime DDL, repository `INSERT OR IGNORE`, boolean predicates and
  timestamp shape listed in part 32 are addressed.
- **Queue listing.** `listJobs()` orders by `rowid`, which PostgreSQL does not
  have. `order by created_at DESC, id DESC` is the portable shape, but it
  changes the ordering the admin table currently shows.
- **Outbox pruning.** `cleanProcessed()` uses `datetime('now', '-' || ?)`, so
  the daily prune is SQLite-only.
- **One transaction per batch.** A conflict rolls back every status update in
  the batch and re-runs its handlers. Claiming each row before delivering it is
  the narrower shape and is not written.
- **Retry duplication.** The outbox restates `TransactionRetry`'s backoff
  because isolation cannot be passed through `ConnectionInterface`. Giving the
  contract an isolation parameter is the change that would remove it.
