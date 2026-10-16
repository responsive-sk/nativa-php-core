# 2026-10-16 — the two stale theme branches, reconciled and measured

Decision asked: "try to merge and reconcile; we'll look at the real conflict and
judge the result". Both were attempted for real, in scratch worktrees off
`master`, with the module slices re-installed from the worktree's own catalogue
and the suite run — not judged from the diff alone.

Verdicts are the user's; this records what the attempt cost and proved.

## `feature/erik-minimal-home` (`bb7833b`, 2026-10-12) — CLEAN MERGE

- `git merge-tree --write-tree master feature/erik-minimal-home` → **exit 0**,
  no conflicted paths.
- Real merge in a worktree: automatic, no edits needed.
- Effect: `modules/Erik/Theme/ErikTheme.php` 272 → **567 lines** (hero +
  four `homeSection()` blocks + inline hero SVG), `erik.css` +311,
  `ErikThemeTest.php` +189 (7 new homepage tests).
- Verified: `composer test --filter Erik` → **11 tests / 91 assertions**, and the
  full suite → **291 tests / 1248 assertions, 2 skipped, exit 0**.

## `feature/griffe-hero-slide` (`111e915`, 2026-10-12) — ONE conflict, resolved

- `git merge-tree` reported **one** conflicted file:
  `modules/Griffe/Theme/GriffeTheme.php`; the other two auto-merged.
- The conflict is in `heroSlide()`:

  - `master` renders `. '<div class="gr-container">' . '<h1 …>' . self::revealLines($title, true)`;
  - the branch renders `. self::heroVisual() . '<div class="gr-container">';` plus an
    eyebrow kicker, then `self::revealLines($title)`.

  These are not two answers to one question: the branch adds the decorative
  stage (visual + kicker) while `master` has since changed `revealLines()`'s
  signature (`bool $revealed = false`, `gr-anim gr-in` for the hero pre-paint).
  `revealLines` itself did not conflict, so the merged file already carries
  master's two-argument version.
- Resolution applied: **the branch's composition + master's argument** —
  `heroVisual()` + kicker + `revealLines($title, true)`.
- Verified: `composer test --filter Griffe` → **30 tests / 126 assertions**, and
  the full suite → **285 tests / 1186 assertions, 2 skipped, exit 0**.

## Reproduce

```bash
git worktree add --detach /tmp/merge-erik master
cd /tmp/merge-erik
cp -a <main>/vendor/. vendor/            # trailing /. — contents, see trap 3
cp -a <main>/src/Modules/. src/Modules/   # trailing /. — contents, see trap 3
cp -a <main>/storage/. storage/
ls src/Modules | wc -l                    # must be 22, not 5
git merge --no-commit --no-ff feature/erik-minimal-home && composer test
# griffe: same, then resolve the heroSlide() hunk as above and
# bin/cms module:remove Griffe --force && bin/cms module:add Griffe && composer test
```

Three setup traps, all paid for, worth not re-learning:

1. A symlinked `vendor` fails `ModuleLifecycleTest`'s tree hash
   (`RecursiveDirectoryIterator` does not follow symlinks).
2. `module:remove` + `module:add` for every module in `ls` order destroys the
   install set — `Cardboard` ↔ `Outbox` are circular, so the dependencies must
   already be installed per module. Copying the main tree's `src/Modules` and
   reinstalling only the module under test is the cheap correct route.
3. **`cp -a <main>/src/Modules .` — the line this patch used to recommend — is
   wrong, and its failure is indistinguishable from success.** A fresh
   `git worktree add` of a repo whose module slices are gitignored *already has*
   a `src/Modules` (Core, Database, Tooling). So `cp -a` copies the directory
   **into itself** as `src/Modules/Modules`: five installed modules instead of
   22, **267 tests skipped**, and every module test reporting OK because most of
   them never ran. The suite is green and means nothing.

   The tell is not the output. It is `git status` showing an untracked
   `Modules/`, and `ls src/Modules | wc -l` returning 5. The fix is the trailing
   `/.`, which copies contents.

   This was paid on 2026-10-16 re-running this patch verbatim: the re-measurement
   produced a green suite from a broken environment, and only the module count
   and the stray directory gave it away. **A green suite was the least
   informative signal available.**

Patches (against `master`) were written to `/tmp/erik-home-merge.patch` and
`/tmp/griffe-hero-slide-merge.patch`; the branches themselves are untouched, so
the work is reproducible without them.

## Closed — 2026-10-16 17:46, PRs #8 and #9

The verdict was asked for and given: **merge both**. Re-measured against the
`master` of the moment, **13 commits** past what this patch measured, and the
numbers still hold exactly — Erik **91** assertions, Griffe **126**. PR #8 took
the clean merge; PR #9 took the same conflict resolution as above, unchanged,
and its body states the caveat this patch could not: `master` has since rewritten
`GriffeTheme.php` by +68/−44 and moved `GriffeThemeTest.php` through seven
commits, where `ErikTheme.php` was never touched at all. Correct and green is not
the same as wanted.

`master` after both: **360 tests / 1557 assertions**, admin-smoke **85 checks**,
`master` CI run `38942306768` green.
