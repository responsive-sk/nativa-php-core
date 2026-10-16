# Five defects that a green suite, a clean PHPStan and a review by eye all accepted

**Date:** 2026-10-16
**Files:** bin/cms, src/composition.php, modules/Outbox/Interfaces/CLI/Command/ProcessOutboxCommand.php, modules/Blog/Interfaces/CLI/Command/GenerateImagesCommand.php, modules/Queue/Application/Services/DatabaseQueue.php, modules/Blog/Application/Services/{CoverJobs,CoverRenderHandler}.php, modules/{Comments,Settings,Users}/tests/*.php, src/Modules/Core/Interfaces/CLI/{MigratorInterface,CliOutput}.php, bin/build/admin-smoke.sh, .github/workflows/ci.yml, lekalo/modules/queue/*.yaml, docs/kernel-status/07-ci-modules-gate.md, docs/modules.md
**Severity:** medium (the queue retry reddening a run, the kernel contract naming a dev package), low (the rest), process (the check count)

## Problem

A single day, two merged pull requests, nineteen commits. Five defects survived
past every gate this repository has. None of them was hard to find; three of
them were found only by **running** the thing rather than reading it, and one of
those three had already been published in the merged documentation.

1. **`--no-prune` pruned.** Porting `ProcessOutboxCommand` off symfony Console
   wrote `isset($options['prune-interval']) || isset($options['no-prune'])` —
   so the flag that says *do not prune* was the flag that pruned. Caught by the
   module test written in the same round.

2. **A successful cover render exited 1.** The exit check was inverted
   (`0 === $generated` against a counter that counted attempts, not
   successes). Caught by running `generate-images --slug <one>` and reading the
   exit code — the file was written and the command reported failure.

3. **A typo'd `--slug` exited 0.** The command rendered nothing and printed a
   healthy summary. The docblock argued the opposite was the point of `--slug`.

4. **One queue retry reddened the whole run.** `DatabaseQueue::work()` released
   a failing job with **no delay**, writing `available_at = now`, so the next
   iteration of the same loop found the row again: `max_attempts` bounded
   *retries*, not *minutes*. A `queue:work` call that would have succeeded on the
   next cron tick already exited 1. A `1` that means "try again in thirty
   seconds" is a `1` nobody reads after the third night.

5. **The gate claimed 79 checks; it emits 78.** Boot 31 recorded the number and
   the record was **merged to master** before the next commit measured it again.

## Root Cause

**The first three are one habit: a port was written from the old file's shape
instead of from the behaviour being ported.** `InputOption::VALUE_REQUIRED`
accepts `--limit 50` as well as `--limit=50`; the first draft of the parser
accepted only the `=` form and **silently ignored** the space form. `Command::FAILURE`
is `1` and `Command::SUCCESS` is `0`; the first draft compared the wrong side.
And a `--slug` that matches nothing is not a run with nothing to report — it is
a typo, and the exit code is where a typo is visible.

**The fourth is a seam that was declared and never exercised.**
`DatabaseQueue::__construct` has accepted `?callable $now` since it shipped.
**No test had ever passed it.** Without a clock, a row released into the future
is simply invisible to the test, so the retry path could not be observed at all
— which is exactly why the defect survived every review that read the code
carefully. `FileTaskMutex` had the same seam and `FileTaskMutexTest` *did* use
it; the pattern existed in the tree and simply was not applied twice.

**The fifth is the measurement itself.** The count was taken from a **filtered
run** rather than by counting what the script emits. This repository has now made
that mistake three times in three places — `make lint`'s `|| true`, a `$?` read
after a pipe, and a `grep -c` on a command's stdout — and each time the symptom
was the same: **a gate that reports success while proving nothing.**

There is a sixth, larger one that is not a defect so much as a structural fact,
recorded because everything above is an instance of it. `MigratorInterface::run()`
was declared `CliOutput | OutputInterface`, where `OutputInterface` is
`symfony/console` — a `require-dev` package. It **worked**, because PHP silently
skips a class it cannot resolve when matching a union type. The tree's central
claim, that its runtime has no Composer dependencies, rested on a language
behaviour nobody had written down. It survived a squash merge, a PHPStan level 9
clean run, a code review, and a CI gate — because nothing in the pipeline was
capable of noticing.

## Solution

- **1, 2, 3** — the tests that caught them were kept and renamed after what they
  now pin (`aFailingJobWaitsOutItsBackoffAndTheRunExitsZero`,
  `anUnknownTemplateExitsOneAndListsTheValidOnes`,
  `aSlugThatMatchesNothingExitsOne`). An unknown option is an **error** on both
  commands: symfony refused it, and a typo that renders everything with the
  defaults while reporting success is worse than the crash it replaced.
- **4** — `work()` releases with `RETRY_BASE_SECONDS * 2 ** (attempts - 1)`,
  capped, because `max_attempts` has no upper bound and an uncapped power
  schedules a row years out. The formula matches Media's Cloudinary client, so
  the tree's two retry paths read alike. The tests walk 30s → 3600s on an
  **injected clock** and would fail if the cap went away.
- **5** — corrected in place rather than superseded, with the measurement
  attached, because a log whose subject is measured states cannot carry an
  off-by-one. The whole chain is now measured end to end: 78 (master) → 81 →
  85.
- **the kernel contract** — `run()` takes `CliOutput` and nothing else, across
  eleven files. `KernelContractTest` reflects over every published kernel
  contract and fails if a signature names anything outside `Modules\Core`;
  verified as a gate that can fail by putting the union back.

## Verification

- `make admin-smoke` → **85 checks**, exit 0, `storage/` back byte-for-byte.
- `composer test` → 352 tests, 1471 assertions, 1 skip.
- `make lint` / `phpstan` (L9) / `rector-dry` / `cs-fix-dry` → clean / no errors /
  OK / 0 of 96.
- A **fresh worktree with the catalogue installed and no `vendor/`**:
  `bin/cms migrate` → 10 databases, exit 0; `outbox:process` and
  `generate-images` both load and run; `queue:work` and `schedule:run` refuse
  cleanly with exit 1. Same shape the `admin-smoke` and `boot` jobs use.
- The producer proved **both ways**: wired, a pushed `blog.cover` renders a
  2553-byte SVG; **without** the wiring the same job parks with `No queue handler
  registered under "blog.cover"`.
- The Lekalo gate measured: a `ref` to a symbol that does not exist gives
  `lekalo validate` exit 1 and `make lekalo-check` exit 2 — taken **without a
  pipe**, because the first attempt read `$?` after `| tail` and got `tail`'s.
- The check-count chain measured by replaying the gate from worktrees at
  `75d50a9` and `06aff20`, one of which carries a `bin/build/admin-smoke.sh`
  byte-identical to master's.

## Prevention

- **Port the behaviour, not the file.** When replacing a framework call, write
  down every accepted form of the original — `--limit 50` as well as
  `--limit=50`, `FAILURE` as well as `SUCCESS` — before writing the
  replacement. A parser that silently ignores an input the old one accepted is
  a parser that turns a typo into wrong behaviour.
- **A number you publish is a measurement you must be able to repeat.** Count
  what the script emits; never take the count from a filtered run, a pipe, or
  memory. The likely cause belongs next to the correction, or the same mistake
  returns.
- **A gate that cannot fail is a gate that is not there.** Before recording a
  gate as working, break it on purpose and watch it go red — a negative control.
  This costs minutes and is the only thing that distinguishes a gate from a
  comment. Today's round did this three times.
- **A seam that exists and is never exercised is a latent bug.** An injectable
  clock, a port, a hook — if no test uses it, nobody knows what it is for, and
  the behaviour behind it is unobservable. Search for the seam's constructor
  argument, not for the feature it was meant to enable.
- **A project-specific workflow belongs to exactly zero skills until somebody
  writes it down.** `module:remove <Name> --force && module:add <Name>` is
  required after every catalogue edit, it is in three of four patches, and
  `grep -rl module:add .agents/skills/` returns **0**. The same day, five module
  reinstalls were performed correctly — by remembering, not by being told.
- **A dependency on a language behaviour is a dependency; write it down.** PHP
  skips an unresolvable class in a union type, which is why the dev-only arm of
  a kernel contract survived months of clean gates. "It works" and "it is
  guaranteed" are different sentences.

## Tags

`#php` `#regression-first` `#mutation-testing` `#negative-control`
`#exit-code` `#cli` `#parser` `#metrics` `#gate-design` `#module-install`
`#project-conventions` `#measurement`