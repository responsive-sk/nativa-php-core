# A mechanical sweep is not a rename: the connection-contract series

**Date:** 2026-11-10
**Files:** modules/*/Infrastructure/Persistence/Statement.php (14 modules),
modules/*/Infrastructure/Persistence/Repositories/*.php,
modules/*/Application/Services/*.php, modules/*/Interfaces/HTTP/*Services.php,
.github/workflows/ci.yml, tests/Architecture/StatementHelperContractTest.php,
docs/kernel-status/26-db-connection-contract.md
**Severity:** medium

## Problem

Fourteen modules moved from a `\PDO`-typed statement helper to the connection
contract (`ConnectionInterface` / `StatementInterface`), one module per commit.
The change is a type rename at almost every site, which is exactly why it kept
failing in ways a rename does not: each round was green locally and then broke
on a caller in another layer, on a ratchet, or on a check nobody was running
against that file.

The failures, all from this series:

```
Media      TypeError: MediaFolderRepository::__construct(): Argument #1
           ($connection) must be of type Modules\Media\...\ConnectionInterface
           at MediaFolderRepository.php:39
Settings   [FATAL] ... must be of type ...ConnectionInterface, PDO given
           called in Standard input code on line 4   (CI, theme pick step)
Queue      Error: Class "Modules\Queue\Application\Services\PDO" not found
           at DatabaseQueue.php:553   (26 errors)
Outbox     theHelpersAreIdenticalApartFromTheirNamespace failed at line 14
```

## Root Cause

Four separate mechanisms, each invisible to the gate that had just passed:

1. **A shared helper's signature reaches callers in a different layer.** The
   helper belongs to a module, but the migrator and other modules call it. The
   Settings migrator used the module helper for its seed and its `PRAGMA`
   probe, so keeping the migrator's raw `\PDO` was not enough: the helper it
   called now refused it.
2. **A same-namespace class hides an import.** `MediaFolderRepository` imports
   nothing that looks like a connection type, because its sibling
   `ConnectionInterface` reference was resolved inside the module. Inserting
   the `use` line "at the top of the imports" matched nothing and produced a
   FQN that does not exist, which the type error reported verbatim.
3. **The CI workflow is a caller.** `.github/workflows/ci.yml` builds a
   `SettingsRepository` in the step that picks the theme. It is not a PHP file
   in `src/`, it does not appear in any `grep` of the module tree, and it runs
   before the suite, so the round looked complete and the pipeline failed.
4. **A ratchet compares bytes, so a copy must keep its text.** The helper is
   duplicated per module and the test asserts the copies are identical apart
   from the namespace. Rewriting the docblock while copying made two stages
   differ for a reason that had nothing to do with the change.

There was also one self-inflicted failure worth recording: removing
`use PDO;` from `DatabaseQueue` while the file still used `PDO::FETCH_ASSOC`
and `PDO::PARAM_INT` produced `Class "Modules\Queue\Application\Services\PDO"
not found` at runtime, not at parse time. The import was only ever needed for
the constants, and the contract having the same constants made it feel
redundant.

## Solution

- Each round copies the contract-stage helper verbatim and changes only the
  namespace line. Migrators keep `\PDO` for DDL and wrap it once:
  `$this->connection = new PdoConnection($pdo, '<db>')` for everything that
  binds. `BlogSearchService` does the same for its per-request in-memory FTS
  index, which no manager owns.
- Statements that are not binds stay spelled out where the spelling is the
  point: `DatabaseQueue::reserve()` keeps `BEGIN IMMEDIATE` through
  `execute()`, because `beginTransaction()` would quietly issue a deferred
  `BEGIN` and the guarded update would stop guarding anything.
- Tests keep their raw `\PDO` for the migrator and the fixture schema, and
  wrap it (`new PdoConnection($pdo, '<db>')`) or ask the manager
  (`connect('<db>')`) for what the services receive.
- `StatementHelperContractTest::CONTRACT_MODULES` grows one name per round, so
  a module cannot drift without being named.

## Prevention

- **Grep for the helper's FQN, not for `\PDO`, before calling a round done.**
  A shared helper is called from migrators, other modules and tests; the type
  change is in the callers, and those are what fail.
- **Treat the CI workflow and `bin/` as call sites.** They are not under
  `src/`, so no module-tree search finds them. `grep -n "instance()->connection("
  .github/workflows/ci.yml` is one line and would have caught the Settings
  failure before the push.
- **Check the module's own imports exist before trusting a type error's FQN.**
  When a repository imports nothing, look for a same-namespace sibling: the
  "insert the use statement" edit can silently match nothing.
- **Copy a ratcheted file byte for byte.** If a test compares copies, the only
  permitted difference is the one the test names. Improvement of the docblock
  is a separate, deliberate change to every copy.
- **A removed `use` is a runtime change, not a cleanup.** Constants
  (`PDO::FETCH_ASSOC`, `PDO::PARAM_INT`) still need the class name in scope
  even when the contract declares the same values.
- **A new doc paragraph can fail a prose gate.** The em-dash contract counts
  every kernel-status line; write the line break instead of the dash, and run
  the content tests when editing docs.

## Tags

`#refactor` `#database` `#connection-contract` `#module-install`
`#ratchet` `#call-site-sweep` `#ci` `#php`
