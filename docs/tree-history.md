# Tree history > the shape, landing by landing

`kernel-status` records **rounds of work**: what was measured, what changed, what
the change measured. This guide records something narrower and harder to
recover later: **the shape those rounds left in the tree**. Which directories
existed when, how many files a landing carried, how the module catalogue grew,
and where the count falls while the project moves forward.

Everything below is read out of git, not out of the plan. The commands are at
the end so any number can be re-measured instead of believed.

Measured 2026-10-24 on `master` at `92b632c`: **244 commits**, 1 018 tracked
files, 13 merged pull requests.

## The spine

One row per landing point on the first-parent line — the commits a reader of
`git log --first-parent` actually walks past. The file count is `git ls-tree -r
--name-only | wc -l` at that commit.

| Date | Landing | Files | The shape it left |
|---|---|---|---|
| 10-11 | `6e25e3a` | 99 | the baseline import: the kernel with `Modules\Core` and `Modules\Database`, a catalogue holding only `.gitkeep`, and `docs/`, `config/`, `src/Themes` present from the first commit |
| 10-11 | `c367b2f` | 109 | the catalogue **mechanism** — `module:add` / `module:remove` with a hash-proven round trip — and `Scratch`, the first module |
| 10-11 | `3235509` | 113 | `Erik`: a theme travelling as a module |
| 10-11 | `57bfa71` | 116 | `Mono` beside Erik, vendored and prefixed |
| 10-11 | `95c088c` | 157 | `Cardboard` enters the catalogue; the kernel's coupling ledger reaches zero edges |
| 10-11 | `5b86b15` | 363 | the twelve-module catalogue lands at once — 169 → 363 files in a single commit |
| 10-11 | `3efa4e5` | 561 | the pnpm workspace comes home (`Frontend/packages`), 363 → 561 |
| 10-11 | `263fdfe` | 565 | `.github/workflows` — the tree starts being measured by something other than a workstation |
| 10-12 | `ecbf403` (PR #1) | 582 | Mono's dev variant switcher |
| 10-12 | `9effbb6` | 591 | `Griffe` ported beside the other two themes |
| 10-13 | `5a0ecb4` | 609 | the theme asset freeze; `public/dist/griffe` committed as a frozen bundle |
| 10-13 | `b68dcac` (PR #2) | 624 | Mono's bundle frozen the same way |
| 10-13 | `fc74e4a` | 659 | `Validation`, the first shared-behaviour module |
| 10-13 | `0792ba0`, `b081a35` | 699 → 705 | `Queue` and `Scheduler`, and the admin pages for both |
| 10-14 | `e7197fd` | 785 | `src/Modules/KernelTheme` — the theme the kernel owns — plus the prod build script and the Lekalo gates |
| 10-15 | `df9c432` | 784 | the contracts refactor: `Modules\Tooling` leaves `Core`. The file count **falls** 785 → 784 while the tree gains a module |
| 10-15 | `30e3e9c` | 790 | the `/mark` access-control fix |
| 10-16 | `ebf619d` (PR #3) | 800 | the security-audit findings |
| 10-16 | `363bc94` (PR #4) | 811 | auth provider discovery, the mail transport, the catalogue installed in CI, and the log split into `docs/kernel-status/` |
| 10-16 | `7335611` (PR #5) | 811 | `admin-smoke.sh` from 29 checks to 75 — a gate that walks content, the anonymous world and the workers |
| 10-16 | `3177e45` (PR #6) | 814 | `bin/cms queue:work` and `src/composition.php` (the handler pairing outside the web process) |
| 10-16 | `e41462e` (PR #7) | 824 | module commands reachable without `vendor/`; the first real queue producer |
| 10-16 | `5379eb8`, `5464936` (PR #10, #11) | 826, 831 | the cover delete, and the retention `failed_jobs` never had |
| 10-17 | `2a2aa17` | 867 | the themes are filed under `modules/Themes/` — top-level module directories fall 21 → 18 while files rise |
| 10-17 | `8f4a8ed` | 942 | `Boson`, server-rendered PHP with no Twig and no Lit runtime |
| 10-21 | `fc09595` | 966 | `fixtures/` — the theme demo pages rendered from the themes themselves |
| 10-22 | `4147809` (PR #12) | 982 | the Mago toolchain (`mago.toml`, the guard gate) |
| 10-22 | `c6c08b9` | 990 | this tree's guides published on a reading surface |
| 10-24 | `92b632c` (PR #13) | 1 018 | a theme declares its own admin-editable settings; `src/Themes` grows by a kernel-side API |

## Three era boundaries

The table is 244 commits cut to 28 rows. The cuts that matter are the ones
where the **kind** of tree changed, not the ones with the largest diff.

**1. A flat catalogue (10-11 → 10-12).** The first four commits after the
baseline build the mechanism before there is anything to put in it: transport,
hash round trip, `module:add`. Then the twelve modules arrive in one commit. In
this era a theme is simply a module — `modules/Erik`, `modules/Mono` sit beside
`modules/Blog`, and nothing in the path says "presentation".

**2. The kernel grows organs (10-14 → 10-15).** `src/Modules/KernelTheme`
appears on 10-14 and `src/Modules/Tooling` on 10-15, the second by *leaving*
`Core`. This is the era where the kernel stops being "Core + Database" and
becomes "Core + Database + the theme the kernel owns + the tooling that
measures modules". `tests/Architecture` starts on 10-15 as well: the tree
begins shipping code whose subject is the tree.

**3. Presentation becomes a family, and installation becomes state (10-16 →
10-17).** `2a2aa17` moves the themes under `modules/Themes/` — the first commit
in this history where a directory count **falls** while the project grows. In
the same two days the installed slice separates from the catalogue: `.gitignore`
ignores `src/Modules/*` except the four the kernel owns, so `modules/<Name>/` is
the committed source and `src/Modules/<Name>/` is operator state, like a
database file.

**After that** (10-18 → 10-24) the shape is stable and the work is inside it:
the page cache, Boson, the docs surface, the Mago toolchain, the theme settings
API. The last landing adds 79 files and moves no directory.

## The module ledger

| Module | Landed | How it arrived |
|---|---|---|
| `Scratch` | 10-11 `c367b2f` | with the catalogue mechanism |
| `Erik` | 10-11 `3235509` | the first theme, as a module |
| `Mono` | 10-11 `57bfa71` | beside Erik |
| `Cardboard` | 10-11 `95c088c` | the admin module |
| `Audit`, `Blog`, `CardboardKit`, `Comments`, `Locales`, `Media`, `Outbox`, `Pages`, `Questions`, `Security`, `Settings`, `Users` | 10-11 `5b86b15` | the twelve that arrive together |
| `CardboardKit` | retired 10-12 `66d0704` | merged into `Cardboard` — the only module this tree has removed |
| `Griffe` | 10-12 `9effbb6` | the third theme |
| `Validation` | 10-13 `fc74e4a` | shared checks, extracted once two modules needed them |
| `Queue`, `Scheduler` | 10-13 `0792ba0` | the workers |
| `Jednotka` | 10-17 `52b2cf6` | a showcase theme ported as a module |
| `Boson` | 10-17 `8f4a8ed` | the theme that needs a build |

A clone carries four slices under `src/Modules/` — `Core`, `Database`,
`KernelTheme`, `Tooling`. Everything else in the ledger is **installed**, not
committed: `bin/cms module:add <Name>` copies it in, `module:remove` takes it
out. That is why the ledger and the file count are different questions.

## The directory ledger

Paths that appeared *after* the baseline, in the order they appeared:

| Path | First landing | What it is for |
|---|---|---|
| `lekalo` | 10-11 `e6f9755` | the tree's own semantic skeleton |
| `bin/build` | 10-11 `19b0356` | the extraction check and the build scripts |
| `Frontend/packages` | 10-11 `3efa4e5` | the pnpm workspace — theme sources, tracked; `node_modules` is not |
| `storage/blog` | 10-12 `6520e5e` | the article corpus, which is the source of truth for `/blog` |
| `public/dist` | 10-13 `327942a` | frozen theme bundles; only the frozen ones are tracked, on purpose |
| `src/Modules/KernelTheme` | 10-14 `e7197fd` | the theme the kernel can never not have |
| `src/Modules/Tooling` | 10-15 `5f1564a` | `bin/cms`'s module tooling, out of `Core` |
| `tests/Architecture` | 10-15 `c589640` | gates about the tree's shape rather than its behaviour |
| `docs/kernel-status/` | 10-16 `46625b5` | the measured log, split by round when one file stopped being read |
| `modules/Themes/` | 10-17 `2a2aa17` | the five themes as one family |
| `fixtures` | 10-21 `fc09595` | demo pages rendered from the themes |
| `mago.toml` | 10-22 `4147809` | the fourth gate tool, stated declaratively |

`docs/`, `config/`, `modules/`, `src/` and `src/Themes` were in the baseline —
a reader who assumes "docs were added later" will misread the first row.

## What the numbers are, and are not

- **Tracked files.** No `vendor/`, no `storage/data/*.db`, no installed slice
  except the four above, no `node_modules`. The count describes the tree git
  hands a clone, not the bytes a deployment serves.
- **Not progress.** `df9c432` removes a file while adding a module, and
  `2a2aa17` lowers a directory count by 3 while adding 2 files: a refactor that
  moves code between owners is a shape change, and shape changes can be
  negative. A count that only rises would be a sign the tree is accreting.
- **One landing is one commit.** PRs #5–#12 were squashed, so a four-commit
  round arrives as a single row here; the PR body is the only place its internal
  order survived. PRs #1–#4 and #13 landed as merge commits, and their branch
  commits are still in the history under the merge.
- **Some rows are a branch, not a commit.** `5a0ecb4` and `0792ba0` are merges
  of feature branches whose individual commits are not on the first-parent line;
  the date is the merge's.

## Reproduce it

```bash
# the spine: every landing point on the first-parent line
git log --first-parent --date=short --format='%h|%ad|%s' master

# one row of the table, for any commit
c=92b632c
git log -1 --date=short --format='%ad %h %s' $c
git ls-tree -r --name-only $c | wc -l

# when a module entered the catalogue
git log --diff-filter=A --date=short --format='%ad %h %s' -- modules/Boson | tail -1

# when a path first existed at all (`tail -1` > git log is newest first)
git log --diff-filter=A --date=short --format='%ad %h %s' -- tests/Architecture | tail -1
```

The `tail -1` is the whole trick of this document: `git log` walks backwards, so
the first commit that added a path is the **last** line, not the first. Reading
it the other way gives today's date for every path in the ledger and looks
plausible, which is why it is written down here.
