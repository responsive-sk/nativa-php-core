# 29. One authored tree

Measured 2026-11-18, after the theme import, the extract-check retirement
(record 28) and the first publish of the combined tree.

## What was true before

Four repositories served the project, and three of them held code: this tree
(the kernel and the module catalogue), `evan70/nativa-themes` (six theme
modules plus the static gallery) and `evan70/nativa-minimal` (the private
showcase whose kernel slice this one was imported from). The fourth,
`responsive-sk/nativa-core`, was already generated.

Two gates compared checkouts instead of asserting behaviour. `extract-check`
compared this tree with the showcase, and `themes-sync` compared the theme
catalogue with the themes repository. Record 28 retired the first, after
measuring it red on the machine and `NOT RUN` in CI. This record covers the
second half of the same problem: a catalogue that was a copy of another
repository is a catalogue nobody can fix in place.

## The move

The themes repository was measured before it was merged: `identical=23
diverged=0` on 2026-11-18, so the import carried history and no content
change. The merge commit `6c29eec` brought 221 files (36,994 insertions, 135
deletions). The six theme modules now sit at `modules/Themes/<Name>/`, and the
gallery is `themes/gallery/` with 206 tracked files.

`bin/build/sync-themes.sh`, its `themes-sync` target and its CI job were
removed in the same round, and the repository was archived read-only. That
lasted four days: on 2026-11-22 it was unarchived and publishes the gallery
again at `https://evan70.github.io/nativa-themes/`, because the copy in this
tree had drifted: its `boson/`, `jednotka/` and `mono/` pages were 42 pages
behind a re-render. `themes/gallery/` here is the copy; its
`gallery-pages.yml` and `gallery-pages-check.yml` are disabled and the
`responsive-sk.github.io/nativa-php-core` Pages site is gone, so one gallery
has one publisher.

## What the one tree changed in practice

- **The linter lost its only exception.** `modules/Themes` was excluded because
  a fix there became a conflict with the mirror. With the mirror gone the
  exclusion was removed, and linting the directory surfaced 7 findings (six
  budget constants and one redundant concatenation) that were fixed in the
  catalogue the same day.
- **The gallery's default source path moved with it.** `sync-themes.mjs` climbed
  one directory when the gallery was its own repository; inside the kernel it
  sits three below the root. The check is still a local gate: without a sibling
  checkout of the showcase it reports skipped, and that is now stated where the
  path is defined.
- **The published suite caught what the export check cannot.** The export runs
  925 tests with 689 skipped, because the catalogue is not installed there. The
  published CI, which installs it, found the nav contract asserting a Pages
  route that only exists when the module is installed; commit `0afaed1` made the
  assertion follow the installation.
- **A dependency alert belonged to the gallery.** Six Dependabot advisories, one
  of them high, were all `vite` in `themes/gallery/pnpm-lock.yaml`; the pin moved
  to 8.3.8 and the count is 0.

## What the export ships

785 files and the 925-test suite, with the marker naming the source commit
(`f56731d` at this measurement). The gallery, its workflows and the theme
sources the export does not use are pruned, so a push that touches only them
does not change the shipped bytes. Source CI runs 14 jobs; the published tree
runs 9.

## Still open

- **`sync:check` is local only.** It needs a checkout of the private showcase
  beside this tree, so CI can only report it skipped. It protects the gallery's
  copied frontend sources, not the theme modules, and that division was already
  documented in `RULES.md`.
- **The archived Pages build is frozen.** Keeping the URL working and keeping
  it current are different jobs, and only the second one is solved.
- **Whether a theme module carries its build source** (`docs/themes.md`, open
  question 2) is still unanswered. `make assets` builds the gallery and the app,
  not a theme module on its own.
- **The showcase stays a consumer.** Its kernel slice is the ancestor of this
  one, not a second source, and no further merge is planned.
