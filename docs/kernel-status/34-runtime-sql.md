# Boot 69: the runtime SQL stops spelling SQLite

The open list in parts 32 and 33 began with runtime DDL, repository conflict
clauses, the queue's `rowid` tiebreak and the outbox's interval arithmetic.
Those four are now dialect calls. No PostgreSQL server is installed in this
environment, only the client, so the PostgreSQL fragments are again asserted as
text while the SQLite paths run the suites.

## What moved

| Site | Was | Now |
|---|---|---|
| `ViewCounter::ensureTable()` | `INTEGER PRIMARY KEY AUTOINCREMENT`, `DATETIME` | `autoIncrementColumn()`, `timestampType()` |
| `RateLimiter::ensureSchema()` and `tableExists()` | `DATETIME`, `sqlite_master` | `timestampType()`, `tableExists()` |
| `SchedulerServices::queueDatabaseReady()` | `sqlite_master` query | `tableExists()` through `SqlDialect::forPdo()` |
| `MediaFolderRepository::insert()` | `INSERT OR IGNORE` | `insertOrIgnore()` |
| `UserRepository::assignRole()` | `INSERT OR IGNORE` | `insertOrIgnore()` |
| `RoleRepository::assignPermission()` and `syncPermissions()` | `INSERT OR IGNORE` | `insertOrIgnore()` |
| `DatabaseQueue` listings | `ORDER BY created_at DESC, rowid DESC` | `insertionOrder()` |
| `DatabaseQueue::pruneFailed()` | `datetime('now', '-' || ?)` | `shiftedTimestamp()` |
| `SqliteOutboxRepository` listings and `cleanProcessed()` | `rowid`, `datetime('now', '-' || ?)` | `insertionOrder()`, `shiftedTimestamp()` |

`insertionOrder()` answers `rowid` on SQLite and the primary key on PostgreSQL,
so the SQLite ordering the admin tables already had is unchanged.
`shiftedTimestamp()` answers `datetime('now', ?)` or `now() + (?)::interval`.

## The mistake the suite caught

`DatabaseConnectionManager` has two accessors: `connection()` returns the raw
`\PDO`, `connect()` returns the contract. The Scheduler check was moved onto the
dialect through `driverName()`, which `\PDO` does not have; the resulting
`Error` fell into the method's `catch (\Throwable)` and the timetable silently
lost its `queue:work` entry. The test that failed was the one asserting the
entry exists on a migrated database. The fix is `SqlDialect::forPdo()`, which is
why that constructor exists.

Mago also reported two findings in this round: a redundant `readonly` on a
property in an already-readonly class, and a string concatenation that could be
one literal. Both were fixed in the code rather than suppressed.

## What is tested

- `tests/Database` is 76 tests, 178 assertions. The dialect test pins
  `insertionOrder()` and `shiftedTimestamp()` for both drivers.
- The Scheduler, Security and Queue suites are 79 tests, 410 assertions.
- The full suite is 1512 tests, 8668 assertions, one skip; the export gate
  discovers 947.

## Still open

- **Boolean predicates.** `is_active = 1` in `BlogAuthorResolver`,
  `MenuRepository` and `SettingsRepository`, and `published = 1` in
  `FormRepository`, are invalid against a PostgreSQL `BOOLEAN`. Writes such as
  `VALUES (..., 1, ...)` into `users.is_active` are invalid too.
- **Datetime windows.** `AuditMetric`, `BlogMetric`, `MediaMetric`,
  `OutboxMetric`, `UsersMetric` and `QueueMetric` still call
  `datetime('now', :interval)` directly. `shiftedTimestamp()` is the fragment
  they need.
- **Remaining `rowid`.** `AuditLogRepository`, `SubmissionRepository`,
  `SqliteNotificationRepository` and `RestPanel`'s spec queries still sort on
  it. `insertionOrder()` covers the same shape.
- **ClickHouse.** `SqliteSchemaReader` reads `sqlite_master` on purpose: the
  bridge's engine is SQLite by design, so it is not part of this port.
- **The `postgres:16` job** still cannot be green, and the timestamp text shape
  of `TIMESTAMPTZ` has not been measured against a reader.
