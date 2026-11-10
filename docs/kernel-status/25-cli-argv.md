# Boot 62: one argv parser for the kernel's commands

Three commands parsed their own arguments and each had its own idea of what a
typo means. The Clickhouse commands refused an option they had not read, the
Blog command accepted `--format` with no value and fell back to its default,
and the Outbox command refused an unknown option but treated `--no-prune=x` as
data. `bin/cms` had a fourth parser, table-driven, that refused everything it
had not declared.

This round left one parser, in the kernel, and the three module commands now
declare their options to it.

## Before

- `src/Modules/Core`: **154 346 B / 37 files** (8 interface / 27 concrete),
  `KernelShapeTest::MAX_APPROVED` **27**.
- `modules/Clickhouse/Interfaces/CLI/CommandOptions.php`: **94 lines**, shared
  by attach, status and query. `--name=value` and `--name value`; a flag with
  `=value` refused; short options refused; positionals returned to the caller.
- `GenerateImagesCommand::parse()`: **55 lines**. Every option takes a value,
  short aliases `-s/-o/-t/-c/-f`, a bare value option means the empty string
  (the symfony default), positionals refused.
- `ProcessOutboxCommand::parse()`: **55 lines**. `--name=value` and
  `--name value`, one flag, positionals refused.
- `bin/cms parseArguments()`: the table-driven parser from commit `87f927e`.
  `--name=value` only, undeclared options refused, the command name and the
  known ones added to the message.

The four disagreed in ways a test could not see: the same `--format` token was
a default in one command and an error in another, and `--dry-runn` was refused
twice and ignored nowhere, but `--dry-run=1` was an error in Clickhouse and a
string in Outbox.

## What changed

`src/Modules/Core/Interfaces/CLI/CommandOptions.php` is the one parser:
**4 713 B**, `parse()` and `value()`. It sits beside `CliOutput` because a
module command may import the kernel's CLI contract, and it holds no state.

The contract is deliberately narrow:

- `--name=value` and `--name value` are the same option;
- `--name` on a flag is `true`, and `--name=value` on a flag is an error;
- a value option with no value is an error unless the command declares it
  optional, in which case the value is the empty string (the `--format` case);
- a short form is an alias the command declares, never a guess;
- anything not starting with `-` is positional, and the command decides
  whether it accepts one.

The migrations:

| Caller | Before | After |
|---|---|---|
| `clickhouse:attach/status/query` | module `CommandOptions`, 94 lines | `Core\Interfaces\CLI\CommandOptions`, 3 import lines changed |
| `generate-images` | private `parse()`, 55 lines | `CommandOptions::parse()` with `VALUE_OPTIONS` and `SHORT_OPTIONS` |
| `outbox:process` | private `parse()`, 55 lines | `CommandOptions::parse()` with `FLAGS` |
| `bin/cms` | its own loop | `CommandOptions::parse()`, adding the command and known list to the error |

Across the three modules and `bin/cms` the diff is **57 insertions, 243
deletions**; the kernel gained one file of 4 713 B. Blog, Outbox and Clickhouse
were reinstalled with `module:remove --force` and `module:add` after the
catalogue edits.

## Behaviour now

- `php bin/cms queue:work --lmit=3` prints
  `cms: unknown option "--lmit" for queue:work; known: --limit=` and exits 1.
- `php bin/cms outbox:process --lmit=5` prints
  `outbox:process: unknown option "--lmit".` and exits 1.
- `php bin/cms generate-images --formt svg` prints
  `generate-images: unknown option "--formt".` and exits 1.
- `php bin/cms clickhouse:attach --dry-runn` prints
  `clickhouse:attach: unknown option "--dry-runn".` and exits 1.
- `php bin/cms queue:work --limit 2` and `--limit=2` both work the same batch.

`tests/Architecture/CliCommandOptionsTest.php` pins the `bin/cms` side: every
dispatched command has a table entry and the reverse, the help advertises
exactly the declared options, every declared option is accepted, and every
undeclared one is refused with the command named.

## Gates

- `make lint`, `composer phpstan`, `composer cs-fix-dry`,
  `composer rector-dry`, `make mago-lint` and `make mago-guard` are green.
  Mago's `no-insecure-comparison` fired on the first draft's `===` between
  option tokens, so the match is a lookup map rather than an operator.
- `composer test`: **1 138 tests / 7 415 assertions / 1 skipped**.
- `make extract-check`: **186 identical / 102 recorded divergences**, with the
  new file recorded as `OWNED` in `bin/build/kernel-divergence.txt`.
- `make kernel-size`: **159 059 B / 38 files**; Core is **36 files**, with
  **8 interface / 28 concrete** types.

## After

- `src/Modules/Core`: **159 059 B / 38 files** (8 interface / 28 concrete).
- `KernelShapeTest::MAX_APPROVED`: **27 -> 28**, one `runtime` row in
  `docs/kernel-shape.md`, six importers.

## Still open

- `settings:profile` and `user:admin` keep their own single-option loops
  (`--file=`, `--name=`, `--email=`, `--password=`). Each reads one option and
  has its own test, so it is the last copy of the rule rather than a defect;
  the next round can move them the same way.
- `bin/cms` help still spells `--limit=N` while the parser also accepts
  `--limit N`. The table is the owner, so advertising the second form is a
  help-text edit, not a parser change.
