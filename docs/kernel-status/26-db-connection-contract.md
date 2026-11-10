# Boot 63: one connection contract, and a read replica behind it

The manager handed every consumer the same thing: a `\PDO`, or a string name
that led to one. A repository could not say whether a query reads or writes,
and nothing in the tree could route one without asking the other. A read
replica was therefore not a configuration question but a rewrite of 61 files
that type a `\PDO`.

This round adds the shape a repository is typed against and routes reads
behind it. The routing is opt-in: with no replica configured the manager
returns exactly the object it returned before, wrapped.

## Before

- `src/Modules/Core`: **159 059 B / 38 files** (8 interface / 28 concrete),
  `KernelShapeTest::MAX_APPROVED` **28**.
- `DatabaseConnectionManager::connection()` returned `\PDO`; `->exec(` 135,
  `->query(` 64, `->prepare(` 17 places in the catalogue.
- 15 modules carried a checked `Statement` helper taking `\PDO`. The helper
  removed PDO's `false` branch; it did not answer the read/write question.
- `UnitOfWork` and five transaction callbacks type `\PDO` by design: a
  callback receives the raw handle, so anything inside it is invisible to a
  wrapper. Deferred at the time; moved in the round below.

## What changed

Three contracts, one adapter pair, one routing decorator, one intent rule:

| File | Bytes |
|---|---:|
| `Contracts/ConnectionInterface.php` | 2 182 |
| `Contracts/StatementInterface.php` | 1 452 |
| `Contracts/PrimaryReadInterface.php` | 1 367 |
| `Exceptions/ReadException.php` | 833 |
| `Persistence/PdoConnection.php` | 2 832 |
| `Persistence/PdoStatement.php` | 1 574 |
| `Persistence/ReadWriteConnection.php` | 5 200 |
| `Persistence/ReplicaSelector.php` | 2 180 |
| `Persistence/StatementIntent.php` | 2 680 |

`ConnectionInterface` is the slice the tree uses: `name`, `query`, `prepare`,
`execute`, `lastInsertId`, `inTransaction`, `beginTransaction`, `commit`,
`rollBack`. `StatementInterface` is the slice of `\PDOStatement` measured on
the catalogue: `fetchColumn` 56, `fetchAll` 49 (11 of them `FETCH_COLUMN`),
`fetch` 36, `rowCount` 16, `bindValue` 15. Nothing else appears
(`fetchObject`, `bindParam`, `closeCursor`), so the contract stops there.

A statement handed back by the contract cannot be `false`: the adapter raises
where PDO returned `false`. That is the one behaviour every caller already
wished it had, and it is why the contract-stage helper has no `false` guard.

### The routing

`DB_<NAME>_READ` names a replica for connection `<NAME>`, comma separated for
more than one. With it unset, `connect()` returns a `PdoConnection`, the same
handle as before. With it set, `connect()` returns a `ReadWriteConnection`:

- `query()`, `prepare()` of a read, and `fetch*` go to a replica;
- `execute()` is a write, and so is `beginTransaction()`. The first write
  marks the request and every later read goes to the primary (sticky write);
- `onPrimary(callable)` is the explicit escape for a read that must see a
  write from the same request, nested as a stack;
- a replica that raises `ReadException` is dropped from the order and the next
  one is tried; the primary is the last resort.

`DB_READ_STRATEGY` is `random` (default) or `weighted`, with
`DB_<NAME>_READ_WEIGHTS` keyed by connection name, not by index, so a replica
dropped during failover does not shift everybody else's weight.

`prepare()` is the only place that has to guess, because the adapter receives
SQL it did not compose. `StatementIntent` accepts `SELECT`, `EXPLAIN` and
`VALUES` after leading comments and treats everything else as a write. A
misrouted read costs a primary query; a misrouted write would corrupt the
replica, so the guess fails closed.

Replicas open with `PRAGMA query_only = 1` rather than a `mode=ro` DSN, so a
write that reached one fails loudly with SQLite's own error instead of
silently doing nothing.

`http_kernel()` resets the sticky write and the `onPrimary()` scope at the
request boundary. A PHP-FPM worker serves many requests with one container; a
write from the previous one would pin every later read in that worker to the
primary, and nothing would look broken.

### The moved modules

`Audit` was the pilot: its `Statement` helper takes `ConnectionInterface`,
`AuditLogRepository` reads through `onPrimary()` where it must see its own
insert, and `AuditServices` builds the repository from `connect('audit')`.

`Users` then moved whole, because it is where the split pays: ten files
stopped naming `\PDO` (only the migrator still takes the driver). `UserRepository` and `RoleRepository` take the
contract; so do `AuthService`, `PasswordResetService`, `UsersMetric`,
`EnsureAdminCommand` and the three `AuthService` wirings in `Audit`,
`Comments` and `Cardboard`. `UserRepository::delete()` reads the account
INSIDE its transaction instead of before it: beginning a transaction marks the
connection as written, so the lookup that decides which pivot rows to cascade
now runs on the primary, where the row it is about to delete certainly is.

The wiring sites keep the two entry points apart. `RateLimiter` is the
Security module's and still takes `\PDO`, so `AuthServices::auth()` passes it
`connection('users')` while the service and the repository share
`connect('users')` — the same primary handle, one with routing in front.

`UnitOfWork` moved with them. Its callback now receives `ConnectionInterface`,
which is what makes a transaction routable: without it the callback's
statements would bypass the decorator entirely. Nesting is keyed by
`ConnectionInterface::name()` rather than by the handle, because `connect()`
returns a decorator whose handle is not the object the caller holds — a
handle-keyed stack would see the outer and inner scope as two databases and
open a second transaction on the same file. The set of names is bounded by the
databases a process opens, so the `WeakMap` that existed to avoid unbounded
growth became a plain array.

`PdoConnection::pdo()` is gone. It was the escape hatch for exactly these
consumers, and with them moved it had no caller left; a migrator gets its
`\PDO` from `connection()` directly.

`StatementHelperContractTest` pins two stages
(`CONTRACT_MODULES = ['Audit', 'Users']`): each stage is byte-identical inside
itself, and each shape is asserted directly, so a module cannot drift from
`\PDO` to the contract without being named.

## What is not done

- 13 modules still take `\PDO`. Each moves in its own round, with its own
  catalogue reinstall; the two-stage helper test is what keeps the movement
  visible instead of letting both shapes grow.
- Module migrators keep `\PDO` on purpose. DDL runs before any repository
exists and does not read or write rows, so routing it would buy nothing and
cost the one consumer that is happy with the driver.
- Replicas are SQLite files in this tree. Nothing here has been measured
  against MySQL or Postgres replication lag, and the sticky write is a
  request-scoped answer to lag, not a clock.

## Verified

- `composer test`: 1170 tests, 7497 assertions, 1 skipped. The two new cases
  are `tests/Http/ReadReplicaRoutingTest.php`, which dispatches a real route
  through a real `Kernel` with `DB_USERS_READ` pointing at a second file and
  asserts the response says `replica` before the write and `primary` after it.
- `make lint`, PHPStan level 9, Rector dry, PHP CS Fixer dry, `make
  mago-guard`, `make mago-lint`: clean.
- `make extract-check`: OK, 185 identical, 103 recorded divergences
  (`UnitOfWork` is `DIVERGED`: the upstream row is the raw-handle version).
- `make kernel-size`: **184 903 B / 47 files**, delta -153 289 B vs baseline.
  Core types unchanged (8 interface / 28 concrete); Database reads 3
  interface / 8 concrete in the same report.
