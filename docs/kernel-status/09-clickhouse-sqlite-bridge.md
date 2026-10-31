# Part 09 — the ClickHouse client, and the SQLite engine as the bridge

**Boot 46.** One round, one question: *can ClickHouse read the kernel's live
SQLite files without an export, a copy or a second schema?* The documentation
says yes — `CREATE DATABASE … ENGINE = SQLite('db_path')` — and this round ships
the client and the three commands that answer it, with every server-dependent
behaviour named as **not measured** because no ClickHouse server was reachable
in the environment that wrote the round.

## What was read first

The only source that states the engine's behaviour is
<https://clickhouse.com/docs/reference/engines/database-engines/sqlite>. The
facts that became code, not prose:

| doc fact | where it lives |
|---|---|
| `CREATE DATABASE … ENGINE = SQLite('db_path')`, then `SELECT`/`INSERT` | `SqliteAttachment::statements()` |
| declared type containing `INT` → `Int64`, `REAL/FLOAT/DOUBLE` → `Float64`, everything else → `String` | `SqliteTypeMap::inferred()` |
| explicit tables parse `TEXT` into `Date/Date32/DateTime/DateTime64/UUID/Enum8/Enum16/Decimal32–256/FixedString/int widths/Float32–64` | `SqliteTypeMap::parsableFromText()`, `explicitFor()` |
| a `TEXT` value that does not parse is coerced, not rejected | the `--typed` warning line, per column |
| writes lock the whole file; reads are multi-tasked | read-only default in `clickhouse:query` |
| access control is filesystem permissions | no credentials in the DDL; `.env.example` says so |

## What changed

**A catalogue module** at `modules/Clickhouse/` — contracts, domain,
application, infrastructure, CLI and tests — with no Composer package and no
kernel edit beyond three command cases in `bin/cms`. It imports `Modules\Core\*`
and itself; `module:show Clickhouse` prints that closed set.

**A client whose seam is an interface.** `ClickHouseTransport` is the test
seam, so `ClickHouseHttpClient` is `final readonly` and every command test
drives a recording implementation. `JSONEachRow` is parsed line by line, and a
JSON-array body is refused rather than accepted — a whole-body `json_decode()`
would silently keep one row of many.

**A bridge that owns the DDL.** `clickhouse:attach` writes one
`CREATE DATABASE IF NOT EXISTS <prefix><name> ENGINE = SQLite('<absolute path>')`
per `storage/data/*.db` plus every `DB_<NAME>` override ending in `.db` whose
file exists. `--force` drops first, `--checkpoint` folds the WAL in, `--typed`
plans explicit tables, `--dry-run` sends nothing. Paths are resolved locally
with `realpath()` before they are written.

**`status` reads the path back from the server**, through
`SHOW CREATE DATABASE`, and prints a drift line per database that disagrees —
the plan is never its own evidence.

## What was measured

| measurement | state |
|---|---|
| `bin/cms clickhouse:attach --dry-run` | 12 `CREATE DATABASE` statements, one per `storage/data/*.db`, exit 0, no server contacted |
| `bin/cms clickhouse:status` with no `CLICKHOUSE_URL` | `ClickHouse is not configured (CLICKHOUSE_URL, CLICKHOUSE_USER, CLICKHOUSE_PASSWORD)`, exit 1 |
| `composer test` | 941 tests, 6 546 assertions, 1 skipped (pre-existing) |
| `composer phpstan` | 0 errors, level 9, kernel scope |
| `make lint` | clean |
| `make mago-lint` | 0 findings (ceiling 0) |
| `make mago-guard` | 0 findings |
| `make extract-check` | 206 identical, 82 recorded divergences |
| `composer cs-fix-dry` · `composer rector-dry` | 0 fixable |
| `composer test` for the module | `SqliteTypeMap` 63, client 15, bridge 23, commands 17, schema/planner 18 — all green, none skipped |
| `openspec validate clickhouse-sqlite-bridge --strict` | valid |

`Tests\Architecture\NameOwnershipContractTest` moved with the module: the
declaration ratchet went 250 → 260 and the distinct-name ratchet 209 → 210, for
seven `MODULE` guards (the idiom every module test in the tree declares),
`NAME_PATTERN`, and the transport's `MAX_ATTEMPTS`/`BASE_DELAY_MS`. The option
arrays and the retry status list were inlined rather than made constants, and a
test prefix constant was removed when the gate showed that owning `nativa_`
charged ten literal places for it. `literalPlaces` ended at 384, one below the
ceiling.

`ModelCatalogueContractTest` requires every catalogued module to have a Lekalo
slice, so `lekalo/modules/clickhouse/module.yaml` exists; `lekalo lock` reports
the lock checked, unchanged.

## Measured against a real server (2026-10-31)

No server was reachable when the code was written, so the earlier draft of this
record said so. One was installed afterwards: the official static binary,
ClickHouse **26.11.1.973**, started on `127.0.0.1:18123` with a throwaway config
under the gitignored `storage/cache/clickhouse/`. The fixtures were
kernel-shaped SQLite files created with PDO, and every step below went through
`bin/cms` with `CLICKHOUSE_URL` pointed at that server.

| question | what the server answered |
|---|---|
| 1 — `SHOW CREATE DATABASE` stability | Available and it carries the path: `CREATE DATABASE SQLite\nENGINE = SQLite('/…/measure.db')` (the name in the statement is the engine, not the database). `system.databases.engine_full` is **empty** for a SQLite-engine database, so it is not the fallback the proposal guessed; `SHOW CREATE` is the only source. `SELECT count() AS n FROM nativa_zmeasure.article_views` answered `2`. |
| 2 — relative path and symlinks | A file outside ClickHouse's `user_files` is refused: error 81 `PATH_ACCESS_DENIED` — a rule the doc page does not state. A **relative** path is accepted and stored as written (`ENGINE = SQLite('relative.db')`), which is why `attach` refuses one. A symlink inside `user_files` is accepted, stored unresolved, and reading through it returned the real rows. |
| 3 — `TEXT` `''` as `DateTime` | The explicit-table path needs a **normal** database: inside the attached database `CREATE TABLE` answers error 57 `TABLE_ALREADY_EXISTS` (every table is implicit) and `DROP TABLE` answers error 48 `NOT_IMPLEMENTED`. An explicit table in `default` is accepted, `DESCRIBE` shows `DateTime`, and reading the `''` row throws error 41 `CANNOT_PARSE_DATETIME` — an exception, not an epoch and not a silent coercion. |
| 4 — `INSERT` on a WAL file | With a live PDO connection holding 8 272 bytes in `-wal` (`wal_autocheckpoint = 0`), the attached database still read both rows, including the one only in the WAL. `INSERT` through ClickHouse exited 0; `count()` became 3; a fresh kernel PDO connection saw all three rows; the WAL stayed non-empty (16 512 bytes). No failure, no block, no lost pages. |

## What the measurement changed

Three of the four answers moved code or prose, which is the reason the plan
made them measurements instead of assumptions:

- **Explicit tables moved out of the attached database.** The planner now emits
  `CREATE TABLE IF NOT EXISTS <prefix><name>__<table> … ENGINE = SQLite('<path>', '<table>')`
  in the server's default database — `nativa_blog__article_views` for
  `nativa_blog.article_views` — because the attached one refuses the definition.
  Re-measured end to end: `attach --typed` created it, `DESCRIBE` showed
  `Int64/String/DateTime`, and the rows read with `viewed_at` parsed.
- **The `--typed` warning says what happens.** `''` as `DateTime` is rejected
  when read (`CANNOT_PARSE_DATETIME`), not coerced; the warning now says
  "rejected when read", and the docblock records the measured error.
- **The WAL warning was narrowed.** ClickHouse reads the WAL through SQLite, so
  the warning is about file-level copies and about folding the file closed,
  not about the attached database missing rows. The checkpoint stays explicit.
- **`user_files` is documented.** `.env.example` and `docs/modules.md` now say
  the path must live inside ClickHouse's `user_files` directory; the server's
  own `PATH_ACCESS_DENIED` is the refusal an operator will see.
- **The relative-path refusal was the right call**, and `SHOW CREATE DATABASE`
  was the right source for drift.

## What the round did not close

- **No read path in the kernel.** A query endpoint behind the panel would be a
  new public write surface; the module answers `bin/cms` only.
- **No dashboard.** Nothing renders an attached database's health in
  `/mark`.
- **No `INSERT` path by default.** `clickhouse:query --write` can send one, and
  the warning says why it is the operator's decision, but no kernel feature
  routes a write through ClickHouse.
- **The four measurements above.** A round with a reachable server can turn
  them into numbers; until then this record says which questions are open.
