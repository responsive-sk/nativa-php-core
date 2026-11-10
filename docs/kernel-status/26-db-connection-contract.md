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
`connect('users')`: the same primary handle, one with routing in front.

`UnitOfWork` moved with them. Its callback now receives `ConnectionInterface`,
which is what makes a transaction routable: without it the callback's
statements would bypass the decorator entirely. Nesting is keyed by
`ConnectionInterface::name()` rather than by the handle, because `connect()`
returns a decorator whose handle is not the object the caller holds. A
handle-keyed stack would see the outer and inner scope as two databases and
open a second transaction on the same file. The set of names is bounded by the
databases a process opens, so the `WeakMap` that existed to avoid unbounded
growth became a plain array.

`PdoConnection::pdo()` is gone. It was the escape hatch for exactly these
consumers, and with them moved it had no caller left; a migrator gets its
`\PDO` from `connection()` directly.

`Security` followed, which is the module that made the wiring one shape:
`RateLimiter` takes the contract, so every `AuthService` graph now passes a
single `connect('users')` to the service, its repository and the limiter
instead of building the limiter from `connection('users')` beside them. The
limiter is also the first consumer whose writes are unavoidable: counting is an
upsert, so on a routed connection the first `isAllowed()` marks the request
written. That is not a defect, it is what a counter must do, and it is why the
read-heavy surfaces, not the throttled ones, are where a replica pays.

`Comments` was the next one and cost six sites: the helper, one repository,
the dashboard metric that opened its own handle, the factory, and two tests.
The metric is the interesting half of that round: it is a card provider, so it
does not run inside a request that routing already covers, and the only thing
standing between it and the contract was its own `connection()` call. That is
the shape of the remaining work in general, a handle opened where the caller
is rather than passed where the caller is, and each one is found by grepping
for `connection(` rather than by reading the module.

`Questions` followed and cost five sites: the helper, the repository, the
factory, the ratchet and a new module test. The test is the part worth
keeping: Questions had coverage for the entity and the action and none for
the SQL, so the repository's own queries had never run through the wrapper.
It now does, on a temp file, which is the only thing that would notice a
change to how the wrapper rewrites `execute()`.

`Notifications` was the third and cost four sites, plus two outside the
module: its own two tests wrote to the file with a raw handle, and `Users`'
inbox wiring test built the repository with one. Those moved to
`connect('notifications')` too, so the tests now exercise the same wiring the
badge does instead of a parallel path to the same file. The unmigrated-inbox
test still fails for the right reason: `no such table` now arrives from the
wrapper, which is what makes it a test of the wrapper's error path.

`Cardboard` was the fourth. It is one service rather than a repository, and it
runs on every `/mark` render in both the full and the compact layout, which
makes it the busiest reader in the panel. Its two queries failed differently:
`allItems()` has to turn an empty `menu_items` into the hardcoded fallback, and
`hasChildren()` is a `SELECT 1` whose answer decides whether a group starts
expanded. `AdminNavContractTest` pins both through the wrapper on a temp
`config.db`, which the module had no test for before.

`Locales` was the first repository outside the panel's own modules, and its
round found a shape the others did not have: the migrator seeded rows with the
shared helper, so moving the helper put a `\PDO` into a parameter typed
`ConnectionInterface`. The migrator keeps its raw handle for DDL and wraps it
once for the seeding loop, which is the only place in the tree where a
migrator prepares a statement at all. Its two raw-PDO tests moved with the
repository: the in-memory fixture in `TranslationFileFallbackTest` now builds
it through `PdoConnection`, and Blog's escape test stopped opening a throwaway
`locales.db` handle and reads through the manager, so it tests the wiring the
admin writes through.

`Forms` was the first module with four readers rather than one, and its round
was mechanical because of it: two repositories, the metric's two count helpers
and two factories, with no test changes at all. `FormsPersistenceTest` already
drove both repositories through `FormsServices`, so the wiring the round
changed was covered before it changed; the module's only raw `\PDO` was the
fixture hand to the migrator, which keeps it. The metric was the interesting
consumer: it catches `\Throwable` so a missing database leaves the dashboard
without a card, and a routed connection adds a failure mode there (`connect()`
can fail on replica selection), which the same catch already covers.

`Outbox` was the round that found a hole in the ratchet rather than in the
module. The helper is a byte-for-byte copy across the stage, and the first
version of this round edited its docblock to mention the metric as well as the
repository; `theHelpersAreIdenticalApartFromTheirNamespace` failed on line 14
with both texts in the diff, which is exactly the drift it exists to catch. The
copy took the other modules' wording instead. The module itself is one
repository plus a metric with a constructor handle, so a call site could bypass
the factory entirely; `OutboxMetric`'s optional argument is now typed
`?ConnectionInterface` and defaults to `connect('outbox')`, and the two tests
that built a raw handle wrap it in `PdoConnection` so they exercise the same
methods a routed connection has.

`Media` was the biggest surface so far: two repositories, a metric and five
private helpers that took a `\PDOStatement`, all inside one module. Only two of
the helpers needed a signature change (`bindFilterParams()` and the private
`fetchAll()` now take `StatementInterface`); the rest were `fetch`/`fetchAll`
call sites where `\PDO::FETCH_ASSOC` is the default and the constant only said
so out loud. The migrator keeps its raw handle, and it is the one place in the
module that reads a `PRAGMA` (`table_info(assets)`), which the contract has no
answer for by design. The three raw-handle tests were of two kinds: the
`new \PDO(...)` in each `setUp()` is the fixture hand for the migrator and
stays, while the ones the repositories received now come from `connect('media')`,
and the two `$pdo->exec()` column updates became `execute()` calls on the same
connection. `MediaFolderPreviewTest` caught one leftover
`connection('media')` immediately: the TypeError named the class and the line,
which is the whole reason the parameter is typed rather than documented.

`Blog` was three services rather than one owner, and two of them are worth
naming. `ViewCounter` caches a connection in a singleton and created its own
table lazily, so its DDL became `execute()` on the contract: a write that opens
its own transaction inside the manager would be a second writer, not a routing
question. `BlogSearchService` is the case the contract cannot own: the FTS5
index lives in `sqlite::memory:` for one request, no manager has a name for it
and nothing routes it. The handle is still built there with a raw `\PDO`, and
the statement helper gets it wrapped once in `PdoConnection`, the same shape the
Locales migrator uses. Its `MATCH` query and three binds did not change; only
the handle type did.

`Settings` was the round with more callers than classes, which is where the
shape of the change shows. Two repositories moved; five places build them,
because Cardboard and Locales each build a `SettingsRepository` for their own
screens, and the change is one argument at each. The migrator is the part that
could not simply keep its handle: it already used the statement helper for its
seed and its `PRAGMA` probe, so it now keeps `\PDO` for DDL and wraps itself
once in `PdoConnection` for everything that binds, the same split the Locales
migrator uses. `seedMenuItem()` took `\PDOStatement` twice and now takes
`StatementInterface`, which is the only signature in the module that was not
already a value-free rename. The tests kept their raw `\PDO` for the migrator
and the fixture schema and moved only the reads and writes to
`connect('config')`. `ApplyDeploymentProfileCommandTest` already had a `db()`
helper returning `PdoConnection` from its own round, so one call site changed
and no new wrapper was added.

`StatementHelperContractTest` pins two stages (`CONTRACT_MODULES = ['Audit',
'Blog', 'Cardboard', 'Comments', 'Forms', 'Locales', 'Media', 'Notifications',
'Outbox', 'Questions', 'Security', 'Settings', 'Users']`):
each stage is byte-identical inside itself, and each shape is asserted
directly, so a module cannot drift from `\PDO` to the contract without being
named.

## Measured: reads are a small slice of a request

A throwaway harness (a `PDO` subclass timing every `query()`, `exec()` and
`execute()`, placed in the manager's connection map, real `Kernel::handle`
dispatches against copies of the module databases) puts the read share at:

| Request | Median total | Time in reads | Share | Reads |
|---|---:|---:|---:|---:|
| `GET /` | 10.47 ms | 0.13 ms | 1.3% | 4 |
| `GET /sitemap.xml` | 20.86 ms | 0.21 ms | 1.0% | 17 |
| `GET /`, rate limiter active | 11.18 ms | 0.20 ms | 1.8% | 7 |
| `GET /mark`, admin session | 65.61 ms | 1.38 ms | 2.1% | 45 |

25 runs per public case, 20 with the limiter, 15 for the dashboard; medians,
warm page cache, SQLite files on the same disk. Routing itself cost nothing
measurable: `GET /` answered in 10.46 ms with `DB_USERS_READ` pointing at a
copy, against 10.47 ms without it.

Two readings matter. A replica cannot buy more than the read share, and here
that is 1 to 2% of a public request: the 65 ms dashboard is rendering, not
querying, so `DB_<NAME>_READ` is an availability lever in this tree, not a
latency one. And the limiter writes before dispatch (two writes on a
non-loopback request), while a write marks the connection, so on the real
pipeline the reads left for a replica are the ones after it. Over a network
the arithmetic changes: one round trip per query would dwarf everything above,
which is the case a replica exists for.

## What is not done

### What is left, module by module

Three modules still name `\PDO` somewhere a caller can see it. Counted from
the tree, not from memory: type sites outside `Migrations/` and `tests/`,
construction sites outside the module's own installed copy, and test files
that build a raw `\PDO` for these classes.

| Module | Database | Type | Build sites | Raw-PDO tests | Round |
|---|---|---:|---:|---:|---|
| Queue | `queue` | 9 | 2 | 5 | Worker plus a metric, four binds. |
| Health | every | 3 | 0 | 0 | Probes `SELECT 1` through `connection()`. |
| Clickhouse | foreign | 3 | 0 | 3 | Reads a file it does not own, read-only. |

The two columns that decide a round are the last two counts: what a module
builds and where a repository is built from outside it. Queue is the smallest
remaining surface of either kind.

Blog and Media carry the routing value: a public article page reads `blog`
several times and a gallery reads `media`, while the rounds that only touch an
admin screen or a worker do nothing for a reader. Blog also carries the one
query a replica can disagree about, the FTS `MATCH`, which is a reason to move
it deliberately rather than as a mechanical sweep.

Health and Clickhouse are recommendations rather than a backlog. Health's whole
job is
to observe a connection failing, and a routed connection is a different
observation: `connect()` can fail on replica selection, which is a fact about
the replica, not about the database the check claims to probe. Clickhouse does
not open a module database at all; the manager has no name for it, and giving
it one would make a foreign, read-only file look like module state.

Queue is worth naming for the opposite reason: it is possible, it is just not
worth much. A worker that reserves a job writes immediately, the write marks
the connection, and every read after it is on the primary anyway. Moving it
would be a mechanical round with a real regression risk (`reserve()` is the
one place a double-dispatch would hurt) and no latency to win.

- One module remains a real target, Queue, and it is the round argued against
  two paragraphs up. What is left after it is not a backlog: Health and
  Clickhouse are recommendations with reasons, and the two-stage helper test
  is what keeps the movement visible instead of letting both shapes grow.
- Module migrators keep `\PDO` on purpose. DDL runs before any repository
  exists and does not read or write rows, so routing it would buy nothing and
  cost the one consumer that is happy with the driver.
- Replicas are SQLite files in this tree. Nothing here has been measured
  against MySQL or Postgres replication lag, and the sticky write is a
  request-scoped answer to lag, not a clock.

## Verified

- `composer test`: 1186 tests, 7536 assertions, 1 skipped. Besides the route
  through a real `Kernel` (`tests/Http/ReadReplicaRoutingTest.php`),
  `tests/Database/UnitOfWorkTest.php` pins the per-database nesting stack:
  two instances over one database open a savepoint instead of a second
  transaction, a nested failure rolls back to its savepoint, and two databases
  keep two stacks.
- `tests/Database/PdoStatementParityTest.php` replays the binding shape of
  every `bindValue()` call site through `PdoConnection` beside a bare `\PDO`,
  and drives the one call site already on the contract end to end. It exists
  because a wrapper that dropped those bindings passed the whole suite and was
  caught by a booted server, which is a bad way to find a 500.
- `modules/Questions/tests/QuestionRepositoryContractTest.php` runs the whole
  `QuestionRepository` through `PdoConnection` on a temp database, which is
  the module's first test of its SQL rather than of its entity.
  `modules/Cardboard/tests/AdminNavContractTest.php` does the same for the
  admin nav's fallback and its group expansion.
- `make lint`, PHPStan level 9, Rector dry, PHP CS Fixer dry, `make
  mago-guard`, `make mago-lint`: clean.
- `make extract-check`: OK, 185 identical, 103 recorded divergences
  (`UnitOfWork` is `DIVERGED`: the upstream row is the raw-handle version).
- `make kernel-size`: **184 903 B / 47 files**, delta -153 289 B vs baseline.
  Core types unchanged (8 interface / 28 concrete); Database reads 3
  interface / 8 concrete in the same report.
