# Scenario ↔ test binding

> NEW — opened 2026-10-27. Priority B of the three proposals in the
> toolchain review: it closes a correctness hole (a scenario can lose its
> test silently), where A (one tool/gate registry) and C (one owner per
> overlap) only reduce the number of places a change has to touch. A and C
> are deliberately NOT part of this change.

## Why

`openspec` records intent — requirements and their scenarios — and
`openspec validate` proves the model is well-formed. Nothing proves it is
**true**: `#### Scenario: Empty password reports once` names no test, and
`emptyPasswordReportsOnce()` names no scenario, so deleting or renaming the
test leaves every gate green. Measured 2026-10-27: 57 scenarios in 6 spec
files, none of them carrying a machine-readable reference to the test that
holds it.

The tree already closed this exact gap once, for the other data layer.
`lekalo.lock` reports `"adapters":[]` — no PHP adapter, so the model is never
compared with the code — and the bridge that was built instead is three
PHPUnit contract tests (`ModelPayloadContractTest`, `ModelReferenceContractTest`,
`SecurityEventPayloadContractTest`) that read the class **as text** and compare
it with the model. Same move here, on the openspec side.

## What Changes

- **A `PINS:` line in the scenario's bullet list** — the convention:
  `- PINS: <repo-relative test file>::<method>`, or `- PINS: none — <reason>`.
  Measured before writing this change: a temporary change whose scenario
  carried a `PINS:` bullet passed `openspec validate --strict` with exit 0,
  so no openspec fork, schema patch or new file format is needed. The spike
  directory was deleted after the measurement.
- **`tests/Architecture/ScenarioTestBindingContractTest.php`** — reads
  `openspec/specs/**/spec.md` and `openspec/changes/*/specs/**/spec.md`
  (`archive/` excluded, it is history) and requires, per scenario: at least
  one `PINS:`; the file exists under `tests/` or `modules/*/tests/`; the
  method exists in it (`function <name>(`); an exemption carries a reason and
  is counted in a ratchet that can only shrink.
- **Annotations, in phases.** Phase 1 binds the canonical spec
  (`openspec/specs/validation/spec.md`, 7 requirements, 9 scenarios). The
  ratchet then starts at 48 — the five deltas of the active `marko-adoption`
  change, each recorded as `PINS: none — not yet annotated` — and each later
  phase binds one capability and lowers the ratchet: `health` 7,
  `notification-inbox` 10, `page-cache-invalidation` 11, `pagination` 11,
  `translation-file` 9.
- **The gate is green from the first commit** and no new scenario can hide:
  a scenario added without a pin fails the contract test. Only the 48
  pre-existing delta scenarios are grandfathered, with a reason per line.
- **The check tolerates a checkout without a spec tree.** `/openspec/` is
  gitignored (decision 2026-10-14: plans and specs stay local and never go to
  GitHub), so CI has no specs at all. The test therefore skips when the
  directory is missing — the shape module tests already use when their module
  is not installed — and it asserts the floor of files it read when the
  directory IS there, so it cannot pass by reading nothing.
- One line in `docs/` (the convention, next to the openspec workflow note in
  `docs/modules.md`).

## Impact

- **Affected specs:** all six — `openspec/specs/validation/` (canonical,
  annotated in phase 1) and the five deltas, annotated one phase each.
- **Code:** one new test in `tests/Architecture/` plus the new tests phase 1
  proves are missing. `tests/` is inside `phpstan.neon`'s scope, so the new
  test must be level-9 clean; it reads files as text and loads no module, so
  it stays correct on a kernel-only checkout.
- **No new gate in CI:** the test runs in the existing `tests` job. No change
  to `mago.toml`, `phpstan.neon`, the Makefile or the workflow. Because
  `/openspec/` is gitignored it is a no-op there, and the proposal says so
  rather than implying a GitHub gate that cannot exist.
- **Spec authoring cost:** four lines per scenario, ≤ 230 lines across the
  six files.

## Open questions, answered in the change rather than left open

- **Why text and not reflection?** The pinned test may live in `modules/`,
  which `phpstan.neon` deliberately leaves out and which a kernel-only
  checkout does not have. Reading the file as text is what
  `ModelPayloadContractTest` does for the same reason (documented in
  `tests/Architecture/MagoScopeContractTest`'s rationale).
- **Why `none` at all?** Because a scenario without a test is a fact, and the
  honest shapes are "write the test" or "record the decision". A requirement
  that no scenario may be uncovered today would force 48 spec edits before the
  gate could land; a ratchet lands the gate now and still refuses the 49th.
