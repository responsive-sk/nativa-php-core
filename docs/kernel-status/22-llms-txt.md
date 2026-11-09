# Boot 59: llms.txt leaves the kernel for Docs

`/llms.txt` is a documentation discovery surface: it lists the guides a
deployment publishes and points a language model at the Docs module. It lived
in Core even though a kernel-only tree serves no `/docs`.

`LlmsTxtAction` moved to `modules/Docs/Interfaces/HTTP/Actions/`. The Docs
module now owns the route and the test that proves it does not advertise a
missing `/docs`. A kernel-only tree answers `/llms.txt` with 404, and
`robots.txt` omits its `Allow: /llms.txt` line instead of advertising a 404.

This is a module extraction, not a namespace move: the route leaves Core and
travels with the module that answers it.

## Before

- `src/Modules/Core`: **173 675 B / 41 files** (8 interface / 31 concrete).
- `KernelShapeTest::MAX_APPROVED`: **31**.
- Source suite: **1099 tests / 7251 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `LlmsTxtAction` | 3 589 | `Core/Interfaces/HTTP/Actions` | `modules/Docs/Interfaces/HTTP/Actions` |
| `LlmsTxtActionTest` | 2 tests | `tests/Http` | `modules/Docs/tests` |

`RobotsAction` stayed in Core and grew from 1 902 B to 2 100 B: it now takes
the optional `Router` and asks `hasPath('/llms.txt')` before writing the allow
line. The net Core change is 3 589 B out, 198 B in.

## Wiring

`tests/Architecture/KernelShapeTest` lost the action's allow-list row and
lowered `MAX_APPROVED` from 31 to 30. `KernelCouplingTest` lowered its scanner
signal from 70 to 60: moving the action out removed four kernel `Modules\Core`
imports, and the guard's floor follows the tree rather than staying at a number
that no longer describes it.

`bin/build/kernel-divergence.txt` records the old `LlmsTxtAction` path as
`DROPPED` and adds a `DIVERGED` row for `RobotsAction`, whose conditional allow
line is this tree's own behaviour.

The moved test gained the module-test skip guard: a kernel-only checkout
discovers `modules/Docs/tests` but has no `Docs` slice, so it skips instead of
failing on a class that is not installed. Two tests were added to the same file
for both robots branches: absent route and present route.

## After

- `src/Modules/Core`: **170 284 B / 40 files** (8 interface / 30 concrete).
- `KernelShapeTest::MAX_APPROVED`: **30**.
- Source suite: **1101 tests / 7251 assertions / 1 skipped**. The count rose by
  two because the moved test gained the two robots cases; the assertions did
  not move.
- Fresh kernel-only checkout, no `vendor/`: **1061 tests / 3694 assertions /
  871 skipped**, zero errors. HTTP: `/`, `/sitemap.xml` and `/robots.txt` answer
  200; `/llms.txt` and `/mark` answer 404; `robots.txt` carries no
  `Allow: /llms.txt` line.
- `make extract-check`: **186 identical / 102 recorded divergences**. The
  action was already a recorded divergence and is now a recorded drop; the new
  `RobotsAction` row is the one moved file.
- `make themes-check`: **23 identical / 0 diverged** against
  `evan70/nativa-themes@17ebe48`.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- `make dist-check`: clean clone -> **200 files**, no `vendor/`, smoke clean.
- Export: **181 files / 167 tests / 400 assertions / 52 skipped**;
  `make core-export-check` is green.
- Source CI: **16/16 jobs**, run `42684343753` on `214c4d0`.
- Published export: `responsive-sk/nativa-core@f58a8dc`, generated from source
  `214c4d0`; public CI is **9/9 jobs**, run `42684776403`. The public clone
  answers `/`, `/sitemap.xml` and `/robots.txt` with 200, and `/llms.txt` and
  `/mark` with 404; its robots omits the LLM allow line.

## Still open

- `WebsiteStructuredData` is still the only Core service whose consumers are a
  module (`Pages`) and the kernel home. Extracting it cleanly needs a port for
  the home's JSON-LD, not another directory move.
- `SubmitResult` is used only by Comments, Media and Users; it is a candidate
  for a shared module, but that creates a module dependency for a value object
  and needs its own decision.
