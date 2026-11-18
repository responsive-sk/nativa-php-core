# Boot 66, measured on SQLite: constraint errors get names

Step 4 of the plan in part 27. No PostgreSQL server was reachable, so the
PostgreSQL shapes are asserted from synthetic driver failures while the SQLite
shapes are executed against real tables.

## The problem the step names

Both engines report a unique violation and a foreign key violation as the
failure of a write, and both were reaching callers as an anonymous
`\PDOException`. Two things follow from that: a caller that wants to say "this
address is taken" has to parse SQLSTATE itself, and `INSERT OR IGNORE` hides
the difference entirely.

The two engines agree on nothing except that it is a constraint:

| engine | unique | foreign key |
|---|---|---|
| PostgreSQL | SQLSTATE `23505` | SQLSTATE `23503` |
| SQLite | `23000` + driver code `19` + `UNIQUE constraint failed` | `23000` + driver code `19` + `FOREIGN KEY constraint failed` |
| MySQL | `23000` + driver code `1062` (read too) | not read yet |

The SQLite pair is the reason the message decides there: the driver code is
identical, so classifying on it alone would call every foreign key failure a
duplicate.

## What changed

- `SqlState` is the one reader of `errorInfo` and the `SQLSTATE[...]` message
  fallback. `TransactionConflictException` keeps its own rules but the parsing
  is no longer copied.
- `UniqueViolationException` and `ForeignKeyViolationException` are domain
  failures that **extend `\PDOException`**. Every existing `catch
  (\PDOException)` keeps working, and the driver's `errorInfo` travels with the
  exception.
- `ConstraintFailure::translate()` returns a domain failure or the original
  exception unchanged. An unrecognised failure is never guessed at.
- The translation runs in both handles where a write fails: `PdoConnection::execute()`
  for a direct statement, and `PdoStatement::execute()` for everything that was
  prepared first. A repository cannot get an anonymous constraint failure by
  choosing the prepared path.

## What is tested

`ConstraintFailureTest` executes the SQLite shapes (direct and prepared
duplicate, orphaned reference) and asserts the PostgreSQL states from synthetic
failures, including that `40001` stays a transaction conflict and that an
unrecognised state is returned unchanged. `tests/Database` is 70 tests.

## Still open

- **Step 3, the migrations.** Still writing SQLite DDL; nothing here changes
  that.
- **Step 5, concurrency.** `FOR UPDATE SKIP LOCKED`, the SERIALIZABLE outbox
  drain and the READ COMMITTED assumption are untouched.
- **The `postgres:16` CI job.** Until it exists and fails on skips, every
  PostgreSQL branch above is asserted only as text.
- **Call sites can now catch the domain failures**, but none has been changed
  to. `AuthService` still pre-checks `emailExists()`, and the race between that
  check and the insert is exactly the case the unique exception describes.
