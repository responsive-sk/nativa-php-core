# Scenario ↔ test binding — tasks

Each phase: `openspec validate --all` green, `composer test` green,
`composer phpstan` green (the new test is inside its scope),
`composer cs-fix-dry` green. The ratchet only goes down; a phase that cannot
lower it is not done.

- [ ] **Phase 0: the convention and the test.** Write
  `tests/Architecture/ScenarioTestBindingContractTest.php`:
  - reads `openspec/specs/**/spec.md` and `openspec/changes/*/specs/**/spec.md`
    (`archive/` excluded), as text;
  - asserts every `#### Scenario:` carries ≥ 1 `PINS:` bullet, that `none`
    carries a reason, and that the file/method resolve;
  - holds the exemption count as a ratchet constant, so raising it is a
    reviewable edit and lowering it is the point;
  - gates against reading nothing (assert the file and scenario counts it
    found, the way `SlovakProseContractTest` asserts its floor of 10 files);
  - **skips when `/openspec/` is absent**, with the reason in the skip
    message — the tree is gitignored, so CI has no specs and this cannot be a
    CI gate (same shape as a module test whose module is not installed);
  - pins THIS change's own delta scenarios to its own methods — the first
    real use, so the convention is not introduced by an exemption.
  Negative controls, run and reported: delete a pinned method → red naming
  the scenario; add a scenario without a pin → red; set the ratchet to 47 →
  red; point a pin at a file outside `tests/` and `modules/*/tests/` → red;
  move `/openspec/` away → skipped with the reason, not green-by-silence.

- [ ] **Phase 1: the canonical spec.** Annotate all 9 scenarios of
  `openspec/specs/validation/spec.md` with `PINS:`. Where
  `modules/Validation/tests/ValidatorTest.php` has the test, name it; where it
  does not (measured candidate: `Errors flatten in field-then-rule order`,
  today covered only implicitly by `customMessagesUseFieldRuleKeys`), either
  add the missing test or record `none` with the reason. Add the convention
  to the spec's own header, where the "keep this file in sync" note already
  lives. This is the phase that proves the convention on real specs.

- [ ] **Phase 2: `health`** — 7 scenarios → `modules/Health/tests/`
  (`HealthCheckRegistryTest`, `HealthChecksTest`, `HealthActionTest`); lower
  the ratchet by 7, or by the number actually pinned plus recorded `none`.

- [ ] **Phase 3: `notification-inbox`** — 10 scenarios →
  `modules/Notifications/tests/`, `modules/Cardboard/tests/NotificationsPageTest.php`,
  `modules/Users/tests/AdminInboxWiringTest.php`.

- [ ] **Phase 4: `page-cache-invalidation`** — 11 scenarios →
  `tests/Cache/PageCacheInvalidationTest.php`.

- [ ] **Phase 5: `pagination`** — 11 scenarios →
  `modules/Pagination/tests/` (`OffsetPaginatorTest`, `CursorPaginatorTest`).

- [ ] **Phase 6: `translation-file`** — 9 scenarios →
  `modules/Locales/tests/TranslationFileFallbackTest.php`, plus the panel-side
  tests the export action has.

- [ ] **Phase 7: ratchet 0.** Remove the exemption table, or reduce it to the
  entries the phases genuinely could not cover — each with its reason and a
  fixed ceiling of zero new ones. `docs/modules.md` gets the one-line
  convention next to the openspec workflow note.

- [ ] **Acceptance for archive.** Every scenario in every non-archived spec
  file carries a `PINS:` that resolves; the ratchet is 0 or holds only
  reasoned `none` entries with no headroom; `openspec validate --all`,
  `composer test`, `composer phpstan`, `composer cs-fix-dry`, `make lint`,
  `make mago-lint` and `make extract-check` all green; the map article's
  "openspec has no path to the code" claim is no longer true.
