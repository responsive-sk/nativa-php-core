# clickhouse-bridge Specification

## Purpose

Read the kernel's live SQLite files through ClickHouse without an export, a
copy or a second schema: a zero-dependency HTTP client, the documented
`ENGINE = SQLite(...)` database attachment, and three `bin/cms` commands
(attach, status, query) that stay read-only unless the operator asks for a
write.

## Requirements

### Requirement: Attach is idempotent

`clickhouse:attach` SHALL emit one `CREATE DATABASE IF NOT EXISTS <prefix><name> ENGINE = SQLite('<absolute path>')` per kernel SQLite file, and the path SHALL be resolved on this host before it is written, so a second run changes nothing. Re-pointing a database SHALL require `--force`, which emits the `DROP DATABASE IF EXISTS` before the `CREATE`.

#### Scenario: A second attach sends the same idempotent DDL

- GIVEN a local SQLite file planned as a ClickHouse database
- WHEN `clickhouse:attach` applies the plan
- THEN the server receives `CREATE DATABASE IF NOT EXISTS nativa_zapply …`
- AND the statement carries the file's resolved absolute path
- PINS: modules/Clickhouse/tests/SqliteEngineBridgeTest.php::applySendsTheCreateAndReportsTheTableCount

### Requirement: Drift is read back from the server

`clickhouse:status` SHALL compare each planned database with the path the server reports in `SHOW CREATE DATABASE`, never with the plan's own copy, and SHALL exit 1 when the server is unreachable or any difference is printed.

#### Scenario: A moved file is reported as drift

- GIVEN a planned database at `/new/storage/blog.db`
- AND a server whose `SHOW CREATE DATABASE` names `/old/storage/blog.db`
- WHEN `status` compares them
- THEN one drift line names both paths
- PINS: modules/Clickhouse/tests/SqliteEngineBridgeTest.php::driftNamesABackendThatReadsADifferentPath

### Requirement: A write needs --write

`clickhouse:query` SHALL accept only statements starting with `SELECT`, `WITH`, `SHOW`, `DESCRIBE` or `EXPLAIN` unless `--write` is passed, and a refused statement SHALL NOT reach the server.

#### Scenario: An INSERT without the flag never reaches the server

- GIVEN the statement `INSERT INTO t VALUES (1)`
- WHEN `clickhouse:query` runs without `--write`
- THEN it exits 1 with a refusal naming the flag
- AND the transport receives no request
- PINS: modules/Clickhouse/tests/QueryCommandTest.php::aWriteWithoutTheFlagNeverReachesTheServer

### Requirement: Explicit typing warns per column

`clickhouse:attach --typed` SHALL write an explicit `CREATE TABLE … ENGINE = SQLite(…)` for each table with at least one column whose declaration names a type parsable from `TEXT`, and SHALL print one warning per such column. The table SHALL be created in the server's default database as `<prefix><name>__<table>` — measured: the attached database refuses the definition with `TABLE_ALREADY_EXISTS`.

#### Scenario: Each typed column earns one warning

- GIVEN a table with `uid UUID` and `price DECIMAL(10,2)`
- WHEN the explicit table is planned
- THEN exactly two warning lines name those columns and their ClickHouse types
- PINS: modules/Clickhouse/tests/TypedTablePlannerTest.php::theWarningCountMatchesTheExplicitlyTypedColumns
