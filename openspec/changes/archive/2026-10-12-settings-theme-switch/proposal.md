> ARCHIVED 2026-10-14 — implemented (Settings provider votes at
> priority -100, `SettingsThemeSelectTest` in tree). Migrated verbatim from
> `.ai-factory/plans/fix-settings-theme-switch.md`; do not edit, history
> only.

# Implementation Plan: Settings theme switch actually switches the theme

Branch: feature/mono-dev-variant-switcher
Created: 2026-10-12

## Original Request
super > funguje nam v settings zmena tem ?

## Settings
- Testing: yes
- Logging: minimal
- Docs: no  # WARN [docs] only — behaviour fix, no new surface to document

## Goal
Make the admin theme `<select>` (`/mark` Settings → Design) do what it says:
choosing an installed public theme switches the rendered public chrome to it,
persisted in the `theme` setting. Scope is the switching mechanism only — no new
theme ships here (griffe stays in its own plan).

## Constraints
- Three-link chain, all three must move or nothing visibly changes:
  1. `SettingsAction::themeSelect()` options (currently `PUBLIC_WHITELIST` only),
  2. `SettingsAction::saveSite()` validation (currently whitelist-only, unknown → `kernel`),
  3. render path `ThemeResolver::resolvePath()` → `ShellContextProviderRegistry::theme()`,
     which NEVER reads the `theme` setting today (`SettingsShellContextProvider::theme()`
     returns `null` by design; only module fixtures vote).
- Merge rule (`ShellContextProviderRegistry`): `theme()` is first-non-null-wins by
  priority, then discovery order. All four providers today sit at `priority: 0`,
  so fixture votes (`Mono → 'mono'`, `Erik → 'erik'`) beat nothing deterministically —
  the Settings vote must sort FIRST, or the fix works only when no fixture is installed.
- Priority is per-provider, not per-answer: lowering the Settings provider's priority
  also promotes its `navigation()` answer. Verify no other installed provider answers
  non-empty navigation (fixtures return `[]` by contract) so the promotion is a no-op
  for menus — pin that with a test, do not assume it.
- `saveSite()` and `themeSelect()` must stay in lockstep (the `themeSelect` docblock
  already demands it): both derive the accepted set from ONE private helper
  (`selectableThemes(): list<string>` = `PUBLIC_WHITELIST` + `ThemeRegistry::all()`
  keys, deduped, whitelist first, registry sorted). A theme the form offers but the
  save path rejects is the exact silent-nothing bug this fix removes.
- `SettingsShellContextProvider::theme()` answers ONLY a resolvable stored value
  (`in_array(PUBLIC_WHITELIST)` or `ThemeRegistry::has()`), else `null` — a stale
  stored name (theme module removed) must degrade to the fixture/kernel answer, never
  to a fatal or an unstyled page. `ThemeResolver` already falls back to kernel for
  unknown names; the provider must not pre-empt that with garbage.
- `SettingsService` is in-memory cached per request and the provider already hits the
  DB per render for navigation — one more cached read adds no query.
- Cardboard (admin) may read `ThemeRegistry` (kernel-owned, `Modules\Core\Interfaces\HTTP`)
  — same direction as its existing `ThemeResolver` import, no new module coupling.
- The existing test `theProviderAnswersTheMenuAndAbstainsFromEverythingElse` asserts
  `theme()` is null — the requested behaviour REQUIRES changing it (provider now votes
  when a valid stored theme exists, still abstains otherwise). Narrow it, do not delete:
  assert `null` for unset/invalid stored values, stored value for valid ones. This is the
  one allowed assertion change, explained here.

## Decisions
- Settings provider priority `0` → `-100` ("Lower sorts first" per `AsShellContextProvider`;
  `int` allows negatives). Fixtures stay at `0`: stored-valid-theme wins over fixture
  votes; unset/invalid stored value keeps today's fixture behaviour byte-identical.
- Seeded default `theme = kernel` unchanged; seed migration untouched.
- No `ThemeResolver` change needed (already accepts any `ThemeRegistry::has()` name).
- No whitelist change (`PUBLIC_WHITELIST` stays `[kernel]` — module themes arrive via
  discovery, the whitelist remains the kernel's own set).

## Open questions
- None blocking. Priority `-100` vs any other negative is arbitrary; any value `< 0`
  works while all fixtures sit at `0`.

## Commit Plan
- **Commit 1** (after tasks 1–4): "fix: settings theme select switches the public theme"

## Tasks

### Phase 1: Fix

- [ ] Task 1: Shared accepted-set + select/save widening in `SettingsAction`
  - Add `private function selectableThemes(): array` (`PUBLIC_WHITELIST` first, then
    `sort()`ed `ThemeRegistry::all()` keys, `array_unique`); `themeSelect()` iterates it
    (label style `ucfirst` preserved, ` selected` on current); `saveSite()` validates the
    posted value against it, fallback `PUBLIC_THEME` otherwise. Docblocks updated to name
    the helper as the single source both halves read.
  - LOGGING REQUIREMENTS: no new log lines; the existing `settings.save` audit entry
    already records the stored value. Verify by reading the test output, not logs.
  - Files: `modules/Cardboard/Interfaces/HTTP/Actions/SettingsAction.php`

- [ ] Task 2: Settings provider votes the stored theme (depends on 1)
  - `SettingsShellContextProvider::theme()`: read `SettingsServices::settings()->get('theme')`;
    return it iff is-string AND (`in_array(PUBLIC_WHITELIST, true)` or `ThemeRegistry::has()`),
    else `null`. Priority attribute `0` → `-100` with a docblock line explaining the vote
    must sort before fixture votes while fixtures exist, and that real deployments may
    drop fixtures entirely. Import `ThemeRegistry` (kernel-owned, same direction as the
    `ThemeResolver` import Cardboard already has — no new coupling).
  - LOGGING REQUIREMENTS: no new log lines; an invalid stored value degrades silently to
    `null` by design (same degradation `ThemeKernel` documents for unknown names).
  - Files: `modules/Settings/Interfaces/HTTP/SettingsShellContextProvider.php`

- [ ] Task 3: Tests (depends on 1, 2)
  - Extend `modules/Settings/tests/SettingsShellContextProviderTest.php` reusing its temp-DB
    harness (`DB_CONFIG` + `SettingsDatabaseMigrator`, `SettingsServices::reset()`):
    stored valid theme (seed `kernel`, update to a registry theme when a fixture module is
    installed, else `kernel`) → provider returns it; stored garbage → `null`; unset →
    `null` (narrowed abstain assertion — the one allowed change, see Constraints).
    Priority assertion: Settings provider sorts before any fixture provider in
    `ShellContextProviderRegistry::all()`; navigation still answered (promotion no-op pin:
    header slot still returns the seeded menu).
  - New `modules/Cardboard/tests/SettingsThemeSelectTest.php` (module-test contract like
    Mono's: skip when `src/Modules/Cardboard` absent): `selectableThemes()` contains `kernel`
    + every `ThemeRegistry::all()` key, form marks current ` selected`, and the save-validation
    rule accepts registry names while rejecting unknown ones (test the rule, not the DB —
    `saveSite()` needs a request/auth stack; extract the rule so it is unit-testable, or
    drive it through the same helper both halves share).
  - LOGGING REQUIREMENTS: test-only; PHPUnit output is the log.
  - Files: `modules/Settings/tests/SettingsShellContextProviderTest.php`,
    `modules/Cardboard/tests/SettingsThemeSelectTest.php`

- [ ] Task 4: Gates + live verify (depends on 1–3)
  - `composer test` exit 0 (status via `${PIPESTATUS[0]}`), `composer phpstan`, Rector
    dry-run, `cs-fix` — fix causes; no other assertion changes beyond Task 3's narrowed
    abstain test.
  - Slice sync + `diff -r` clean for touched modules, then Chromium/CDP against local
    `php -S`: set `theme` to a fixture theme via the admin form (or directly), public page
    renders that theme's chrome (`gr-`/`mo-`/`er-` vocabulary check); set garbage → kernel
    fallback, no fatal; remove fixture behaviour unchanged when setting unset.
  - LOGGING REQUIREMENTS: minimal — report failing command + first error block only.
  - Files: (fixes wherever the gates point)
<!-- Commit checkpoint: tasks 1–4 -->
