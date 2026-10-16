# Boot 16 — the admin CRUD flow, and the marker that ate its tables

Verifying the admin flow end to end (the last item Boot 15 left open) found
the write path broken — and not by the admin code.

**What was measured.** `POST /mark/categories/create` with a valid CSRF
token and `category=Verify-CRUD-…` → **500**, while the category *did*
persist (Blog's taxonomy registry is
`storage/data/blog-taxonomy.json`, not SQLite). The log named it:

```
[KERNEL] PDOException: no such table: audit_log
  (Audit/Infrastructure/Persistence/Repositories/AuditLogRepository.php:29)
```

Every admin write records an audit event, so the write was landing and then
dying on its own trail. `audit.db` was empty, and so were `blog`, `comments`,
`outbox`, `core` and `media` — the tree answered 200 everywhere it did not
write, which is why the flow had looked fine.

**Root cause: a stale migration marker.** Migrations auto-run on the first
request and then leave `storage/cache/.migrations-applied`, whose content was
— measured — a single word: `config`. It was written on 2026-10-11 21:48,
when Settings was the only installed module. Boot 14's `module:add` then
installed thirteen more slices **without touching that file**, so the
Bootstrapper kept skipping the run and none of their tables were ever
created. Deleting the marker and hitting `/` re-ran all nine migrators
(`audit, blog, comments, config, locales, media, outbox, questions, users`)
and created `audit_log`, `article_views`, `outbox_messages`; the same POST
then answered **302**, the category persisted, and `audit_log` held its
`categories.create` row.

**The full CRUD cycle, measured through HTTP** (Browser: `curl` with a
session cookie and the form's CSRF token): `create` 302 → present in
`blog-taxonomy.json`; `rename` 302 and `delete` 302, both audit-logged;
a `POST` with no token → **403** (the CSRF guard fires); `login` 302 →
`/mark`; no `[KERNEL] Error` anywhere in the run.

**One caveat, upstream — not fixed here.** `rename` and `delete` rewrite
**article front matter** (`replaceCategoryInAllArticles` /
`removeCategoryFromAllArticles`); they never touch the registry. This tree
has no `storage/blog/` at all, so *every* listed category is registry-only
and both buttons are no-ops — they answer 302, write an audit row reading
`(0 articles)`, and change nothing. `create` is the only button that
persists here. The actions are `Modules\Blog`, imported verbatim, so this is
recorded rather than forked (the same call as the three undefined CSS
classes in Boot 15).

**Gates after the change:** `phpstan` clean (82/82 files), `composer test`
13 tests / 30 assertions / 1 skipped, `extract-kernel.sh --check` OK (57
identical, 38 recorded divergences — the new Bootstrapper rewrite is the
38th).

**Fixed at the cause**, not by pre-creating tables: the marker records "the
migrators of *this* module set have run", so `ModuleInstaller::add()` and
`remove()` now call `Bootstrapper::invalidateMigrations()`. The next request
re-runs the (idempotent) migrators against the new set. A regression test in
`ModuleLifecycleTest` writes the marker, installs the fixture slice, and
asserts it is gone.

**No `migrate` command exists in this tree** — `bin/cms` ships `module:*`
only, so until now the marker was a trap with no way out. The invalidation
makes that a non-issue for the CLI path; `rm storage/cache/.migrations-applied`
is the remaining manual lever, and it is now written down in the
Bootstrapper's own docblock rather than left to be rediscovered.

**Also confirmed on the way:** the login POST records its
`security.auth.attempt` into the *Outbox*, so the same stale marker was
logging `no such table: outbox_messages` on every sign-in — silent in the
response, loud in the log. With the table now in place that line changed to
`[OUTBOX] Failed to process security.auth.attempt (attempt 0/5): No handler
registered for event type: security.auth.attempt` — a second, separate gap
(the Outbox has no handler for the event the login emits) that the missing
table had been hiding. Recorded, not fixed here: the login itself answers
302 → `/mark` and holds the session regardless.

# Boot 17 — the pnpm workspace comes home

Boot 15 answered "`/mark` works but has no pnpm assets" by importing a
**build** and keeping the tree Node-free. That left the honest objection
standing: the imported bundle was production output, and nothing here could
reproduce it. This boot imports the workspace instead.

**What came in:** `Frontend/packages` — 193 files, 1.5 MB, copied verbatim
from the showcase: the pnpm workspace root (`package.json`,
`pnpm-workspace.yaml`, `pnpm-lock.yaml`, `tsconfig.base.json`,
`tsconfig.build.json`), the shared `build/vite-theme-factory.ts` +
`build/strip-at-layer.ts`, and one package per theme (`starter`, `erik`,
`moonshine`, `magazine`, `minimal`, `basic`, `jednotka`). `node_modules`
(59 MB of third-party files in the checkout it came from) is **not** part of
the import and is gitignored, alongside the built `public/dist`.

The factory needs no edit: it derives the repo root as
`<root>/Frontend/packages/../..`, which is `<root>` in this tree exactly as
it is upstream, so `outDir` lands on this `public/dist/<theme>/`.

**Proven end to end, in this tree:**

| | result |
|---|---|
| `pnpm install --frozen-lockfile` | 18 packages, all reused from the local store (offline) |
| `pnpm build` | 7 theme bundles into `public/dist/<theme>/` |
| `diff -rq public/dist ../nativa-minimal/public/dist` | **silent** — byte-identical build |
| hashed names vs the Boot 15 import | identical (`mark-BlzW9kQ5.css`, `moonshine-DtY8mHQK.js`) |
| `/mark` stylesheets, shell JS, 5 fonts | 200, unchanged |

The build logs "…/fonts/mark/Gilroy-*.woff2 — didn't resolve at build time, it
will remain unchanged to be resolved at runtime" for the five Gilroy faces —
correct and expected: those URLs are absolute (`/fonts/mark/…`),
the fonts are tracked in `public/fonts/`, and Boot 15 already verified all
five answer 200.

**`make assets` now builds here** (`cd Frontend/packages && pnpm install
--frozen-lockfile && pnpm build`); the old copy-from-the-showcase target
survives as `make assets-import` for a machine without pnpm. The
`extract-kernel.sh` slice grew `Frontend/packages`, so the import is guarded
rather than merely present: **250 identical / 38 recorded divergences** (was
57/38). A new `SLICE_SKIP` list prunes `Frontend/packages/node_modules` from
both the stage and the walk — without it the check compared a dependency
store and reported every file as "exists only here".

# Boot 17b — `bin/cms migrate`

Boot 16 gave the tree a way to *recover* from a stale migration marker and no
way to *force* one. `bin/cms` was four `module:*` commands by design, and the
Bootstrapper's docblock pointed at a `migrate` command that this tree does
not have. It has one now — a fifth argv-parsed command, zero dependencies
like the rest, built directly on `DatabaseMigrator` and a three-line
`CliOutput`:

```
php bin/cms migrate
  → Migrating audit.db … ✓ Migrated: audit, blog, comments, config, locales,
    media, outbox, questions, users
```

Verified against the Boot 16 failure state: `audit_log` dropped by hand,
`migrate` recreated it, exit 0; an unknown command still exits 1.

# Boot 18 — the two silent gaps the admin flow exposed

Both were found by reading the log after the Boot 16 fix, and both share a
shape: an operation answered 302, and nothing happened.

## `security.auth.attempt` had no handler

```
[OUTBOX] Failed to process security.auth.attempt (attempt 0/5):
No handler registered for event type: security.auth.attempt
```

Sign-in records the attempt into the Outbox; the Outbox's end-of-request
drain (`OutboxDrain::drain()`) registers only the handler the Outbox module
can see — the article-publish cache flush. The security handler lives in the
**Audit** module, because its output is an audit row, and Outbox reaching into
Audit is the module dependency upstream's `ModuleIsolationTest` refuses — a
guard that does **not** exist in this tree (`find` over the whole tree: zero
hits outside `../nativa-minimal`; the kernel half now has one, see "Kernel
contracts" at the end of this file). Upstream wires
the pair in its composition root; this tree's `public/index.php` is the
kernel's half and has "nothing to wire", so the pair was never declared and
every security event landed in `outbox_messages` as `failed`.

Fixed where upstream fixes it — the composition root — under `class_exists`
guards on all three classes, so a kernel-only tree still boots with nothing
to wire (the same trick `LoginAction` uses for its Outbox call, and the reason
no `use` statement appears).

**Measured:** a sign-in now delivers 1 message, writes the audit row
(`security.auth.attempt`, `audit_log` rowid 2 — the table's first security
record) and logs **zero** `No handler registered` lines. The 10 messages that failed before the fix stay `failed`:
`findPending()` selects `pending`, so they are history, not a queue — they are
left as the record of the outage rather than silently replayed.

## Rename and delete never touched the registry

`/mark/categories` lists the **registry** (`storage/data/blog-taxonomy.json`,
where a pre-created category lives) merged with article front matter. Its
Rename and Delete actions only rewrote **front matter**
(`replaceCategoryInAllArticles` / `removeCategoryFromAllArticles`) — so on any
category that lives in the registry alone, both buttons answered 302, wrote
an audit row, and changed nothing. This tree has no `storage/blog/` at all, so
*every* category was in that case.

`TaxonomyService` gained `renameCategory()` / `deleteCategory()` (registry
edit, case-insensitive, first-occurrence-wins dedupe — the same rule readers
apply), and the action now does both halves: the registry and the articles.
The audit detail says which: `(0 articles, registry: renamed)`.

**Measured** through HTTP, registry-only: create → `[\"Reg…\"]`; rename →
`[\"Reg…-renamed\"]` and the list shows the new name; delete → `[]` and the
list no longer shows it.

**This is the catalogue's first fork of an upstream module.** `modules/Blog`
now differs from `nativa-minimal/src/Modules/Blog` in two files, and the
installed copy under `src/Modules/Blog` was synced to match (the slice is
operator state and is gitignored — the catalogue is the source). No
`kernel-divergence.txt` entry applies: the record guards the kernel slice
(`Core`, `Database`, `config`, `Frontend/packages`), not module catalogues.

**Verification gotcha worth knowing:** `php -S` runs under the CLI SAPI, where
`opcache.enable_cli=On` and `opcache.revalidate_freq=180` are both set — the
long-running dev server kept serving the pre-edit action, so the first run
read as "the fix did nothing". The files were correct the whole time; the
server needed a restart. Same class of trap as the Boot 1 opcache incident,
same lesson: restart before believing a null result.

# Boot 19 — CI, and the zero-dependency claim it falsified

`.github/workflows/ci.yml` — six jobs, each mirroring what the showcase's CI
does for its own tree, on the shape a fresh clone actually has (kernel-only:
`src/Modules/*` is gitignored operator state).

| job | what it asserts |
|---|---|
| Lint + PHPStan | `make lint` over every shipped `.php`, then `composer phpstan` |
| Code style | `composer cs-fix-dry` + `composer rector-dry` |
| PHPUnit | `composer test` in the kernel-only shape |
| Assets (pnpm) | the committed workspace builds; the admin manifest keeps its entry keys; no `@layer` survived (Chrome 70 drops the block) |
| Boot (no vendor) | no `vendor/`; `bin/cms migrate` + `module:list`; six surfaces over HTTP; a module installed → 200 → removed → 404; an empty error log |
| Extract-check | **not run** — the guard diffs against upstream (`evan70/nativa-minimal`), which is PRIVATE and unreachable with this run's `GITHUB_TOKEN`; the job states the measurement and how to enable it (Boot 20) |

## What the no-vendor job caught

**The kernel's headline claim was false.** A vendor-less checkout answered
**500 to every request** — `/` included, kernel-only included:

```
[FATAL] Error: Class "Symfony\Component\Console\Command\Command" not found
  in src/Modules/Core/Interfaces/CLI/Command/ServeCommand.php:24
  #2 Router.php(214): class_exists()
  #4 Router.php(40): Router->discover()
```

`Router` discovery read every file under the action dirs and
`class_exists()`-ed it, which makes each class's **parent** a load-time
dependency of *routing* — and at Boot 19 the kernel core itself shipped
three such classes (`MigrateCommand`, `ServeCommand`, the rest of
`Interfaces/CLI/Command/`), all extending `symfony/console`, a **dev-only**
dependency. The divergence record named it as the kernel's one inherited
defect (`Router.php # upstream task 0.3 loader guard not adopted here; Boot 1
 defect still inherited`). Those three are since deleted as dead code — the
guard is not, because module-level commands still extend symfony/console and
`$actionDirs` is the whole of `src/Modules`, route-less files included.

**Fixed by porting upstream's guard**: `Router::mayDeclareRoute()` reads the
file as TEXT and skips anything without a `#[Route(` token before any class is
loaded — permissive in the miss direction, because a file that cannot declare
a route cannot be a route. Our `Router` now differs from upstream in two
comment blocks and nothing else.

**Measured after the fix**, in copies of this tree with no `vendor/`:

| tree | before | after |
|---|---|---|
| kernel-only: `/`, `/sitemap.xml`, `/robots.txt`, `/docs` | 500 | 200 |
| kernel-only: `/nope`, `/mark` | 500 | 404 (never 403) |
| kernel-only: `module:add Scratch` → `/scratch` → `module:remove` | 500 | 200 → 404 |
| **14 modules installed**, no vendor: `/mark`, `/mark/categories` | 500 | 200 |
| `PHP Fatal error` / `[KERNEL] Error` lines in `storage/logs/php-error.log` | not counted before | 0 |

## What the kernel-only run of the suite found

The workstation and CI run the same tests over **different trees**: here
`src/Modules/` holds fourteen installed modules, there it holds `Core` +
`Database`. Three things only the second shape shows — each reproduced in a
copy of this tree with no `vendor/` and no installed slices:

1. **`module:add` failed intermittently** — `Cannot copy
   .../TempHolder/module.json`. `ModuleInstaller::copyTree()` never created
   its destination root, and the iterator yields a slice's contents in
   **readdir order**: a slice whose root holds a file before any directory
   handed that file over while the target did not exist yet. The lifecycle
   fixture is that shape (`module.json` beside `src/`), which is why the same
   test passed on one filesystem and failed on another. Fixed at the contract
   (`copyTree` creates `$to` first); no shipped catalogue entry triggers it
   today — all sixteen hold directories only — so this was a trap waiting for
   the first module that ships a README or a manifest.
2. **`phpstan` failed on the registry test** — five `class.notFound` errors
   for `Modules\Blog\Application\BlogServices`, in exactly the tree the test
   skips in. Solely a *static* problem: the runtime reaches an optional module
   through a class-name string (`LoginAction`'s idiom), but an analyser cannot
   be told "this file is not analysed when the class is absent". The test moved
   **to the module's own directory** (`modules/Blog/tests/`, a second PHPUnit
   suite) — `tests/` is analysed and `modules/` is not, so a test that names an
   optional module's class cannot break the analyser of a tree without that
   module. That is also where such a test belongs: beside the code it covers,
   in the committed catalogue.
3. **`composer test` reported 1 error, not a skip** — the copyTree failure
   above, surfacing through `ModuleLifecycleTest`'s round trip.

After the three: the same copy runs **18 tests / 32 assertions / 5 skipped**
(the Blog registry tests skip without Blog) and `phpstan` reports **no
errors** — against 18 / 45 / 1 and clean on the workstation. `cs-fix-dry`,
`rector-dry` and `make lint` are green in both.

The assets job needed its own correction, and the assertion caught it: the
Vite manifest is keyed by RESOLVED SOURCE PATH (`src/mark/index.css`), not by
the entry name, so the `has("mark")` assertion this workflow shipped with was
false on the first run — a red job for the wrong reason. It now matches the
entries' `name` field (with a negative control proving the query can fail) and
also asserts that the file the `mark` entry names exists.

## Two gates that could not fail

`make lint` ended in `grep -v 'No syntax errors' || true`: it printed every
parse error and exited **0**. It now sends `php -l`'s chatter to `/dev/null`,
lets parse errors reach stderr, and lets `xargs`'s status through — verified by
planting `<?php function broken( {` in `src/`: exit **2** before the fix read
`0`.

`composer cs-fix-dry` and `composer rector-dry` had **no config files** in the
slice, which is worse than a weak gate: the cs-fixer dry run *created* a
config and appended two lines to `.gitignore` — a check that mutates the tree
it checks — and Rector printed `No rector.php config found` and exited 0.

Both configs now exist, taken from the showcase (measured green there: 0 of
385 files) and scoped to the code this repo owns and may restyle:
`src/Modules/{Core,Database}`, `src/Themes`, `bin`, `public`, `tests` — the
same scope `phpstan.neon` analyses. Excluded, deliberately: `src/Modules/*`
elsewhere (installed slices — operator state, so the verdict would depend on
what is installed) and `modules/` (the verbatim catalogue — restyling it turns
the next re-import into a hand-merge).

Rector is **pinned to 2.6.1**, the version the showcase resolves. 2.7.0 adds
`StringClassNameToClassConstantRector`, which rewrote four files that are
byte-identical to the upstream slice — a dev-tool bump that would have bought
four recorded divergences for no behavioural gain. Bump it in a commit that
also restyles the slice and records what changed. The eight files the
gate *did* flag are all mine or the tree's (every one an `OWNED` or already
`DIVERGED` path), and they are fixed; `extract-kernel --check` stayed at
**250 identical / 38 recorded divergences** through all of it.

## Bootstrapper

```
[bootstrapper] No migrators found (add #[AsMigrator] classes under
               src/Modules/*/Infrastructure/Persistence/Migrations/).
```

Correct and expected: `Modules\Database` is the connection manager and the
migrator runner, not a table owner, and the kernel ships no migrations of its
own. A tree that created tables here would be creating a module's schema.

## Not yet in this tree

Present and verified: the kernel slice, the shell port, the kernel theme,
`src/init.php`, the composition root, `config/session.php`, the storage skeleton,
`composer.json` (zero production dependencies, dev-only tooling), the
`.env.example` for the keys the kernel reads, and the Lekalo skeleton
(`lekalo/project.yaml` + `targets/` + one `modules/<name>/module.yaml` per
`src/Modules` directory — 21 modules: `core`, `database`, `kernel_theme` and the
feature modules — id `nativa_core` — the IR grammar takes no hyphens — `lekalo.lock`
committed, `validate` + `validate --strict` green on CLI 0.6.5, 23 rules, 0 findings).

Present since 2026-10-11: the module catalogue (`modules/`, task 3.1–3.2) —
`ModuleCatalog`/`ModuleManifest` scan a slice as text, `bin/cms`
`module:list/show/add/remove` (plain PHP, zero dependencies) moves it, and
`modules/Scratch` is the lifecycle fixture whose round trip
(`docs/modules.md`) leaves the tree byte-identical — proven by
`ModuleLifecycleTest` since Boot 10, with `KernelOnlyBootTest` beside it
(`composer test`, 7 tests, 13 assertions).

Absent, deliberately: a real trimmed design for the kernel theme. It ships no
CSS, so a page it renders is valid and unstyled — plan task 2.2 wants a kernel
theme that was *designed*, not just one that works. (`bin/build/extract-kernel.sh`
+ `--check`, listed here as absent while it did not exist, arrived in Boot 11 and
runs as `make extract-check`; a CI run would need a credential for the private
upstream — see Boot 20, below.)

# Boot 20 — the extract-check linkage that was added and removed, and the four files upstream had not committed

The `extract-check` job printed `NOT RUN` and exited 0 because the guard diffs
this tree against `../nativa-minimal` and a runner has no checkout of it. The
attempt to make it real ended where it started — with `NOT RUN` — and both
halves are worth keeping, because the reason for the failure was measured late
and would have been cheap to guess wrong (it *was* guessed wrong first).

## The attempt

**Layout, first.** `actions/checkout` cannot produce a literal sibling — it
throws on any `path` outside the workspace (its source, `src/input-helper.ts`,
the `path` input: `Repository path '<resolved>' is not under
'<GITHUB_WORKSPACE>'`, compared as `(repositoryPath + sep).startsWith(
workspace + sep)` on absolute paths). So upstream went **inside** the
workspace (`.upstream/nativa-minimal`) and the guard was pointed at it with
`--minimal-root`, the override the script documents for CI. The nested tree is
inert for the diff: the guard walks only `SLICE_DIRS` + `SLICE_FILES`.

**It failed inside the action, and not because of the layout:**

```
Syncing repository: evan70/nativa-minimal
Determining the default branch
Error: Not Found - https://docs.github.com/rest/repos/repos#get-a-repository
```

**`evan70/nativa-minimal` is PRIVATE.** The run's `GITHUB_TOKEN` is scoped to
*this* repository, so `GET /repos/evan70/nativa-minimal` answers **404** — for a
private repository, even to a token that exists.

## How this got mis-diagnosed first (the useful part)

The measurement the attempt rested on was this one:

```
$ git ls-remote https://github.com/evan70/nativa-minimal HEAD
cc79d4ee994dda7ba2f563c8c224a34559cf032d	HEAD
```

Read as "a public read — the repository is reachable without credentials". It
is not a public read: **this machine's git config carries
`credential.helper = store`**, so the call was authenticated by a stored token
and said nothing about visibility. With the helpers out of the picture:

```
$ GIT_CONFIG_GLOBAL=/dev/null GIT_ASKPASS=/bin/true \
    git ls-remote https://github.com/evan70/nativa-minimal HEAD
remote: Repository not found.
fatal: Authentication failed for 'https://github.com/evan70/nativa-minimal/'
```

and the API agrees: `GET /repos/evan70/nativa-minimal` → **404** anonymously,
**200** with the owner's token, which reports `"private": true`.

The lesson generalises past this job: *a local check that passes is evidence
about the local environment until you know what else it used.* "Anonymous" in
a shell with a credential helper is a claim about the helper. The CI job was
that check, run in the one environment that does not have the operator's
credentials.

## Resolution: the linkage is deleted

Searching for a credential the runner has (a PAT secret) is a real option, and
it is written down in the job rather than done: a fine-grained PAT with
`contents: read` on the private upstream, stored as `UPSTREAM_TOKEN`, the
checkout with that token, the guard with `--minimal-root`. Until someone
decides to hold that secret, the job is `NOT RUN` again — now stating the
measured reason instead of "a runner has no checkout of it", which was true but
not the whole truth.

Where the gate *can* run it is unchanged and green: `make extract-check` on a
workstation, against the sibling checkout.

## What the attempt found anyway

Run against a **clone** of upstream — committed state, as opposed to the
sibling's working tree — the guard reported **4 unrecorded differences**:

```
DRIFT: Frontend/packages/jednotka/src/main.ts differs from upstream (not DIVERGED)
DRIFT: Frontend/packages/jednotka/src/jednotka/content.css …
DRIFT: Frontend/packages/jednotka/src/jednotka/toc.css …
DRIFT: Frontend/packages/jednotka/src/jednotka/tokens.css …
```

No one hand-edited them. `cmp` settles it in both directions:

| comparison | result |
|---|---|
| our file vs `../nativa-minimal/<file>` (working tree) | **identical** |
| our file vs `git -C ../nativa-minimal show HEAD:<file>` | differs |
| upstream working tree vs its own `HEAD` | differs (the four are `M` there) |

The import that first carried `Frontend/packages` read a checkout whose
jednotka edits were **not committed yet**. What is committed here is upstream's
next commit. The workstation's `make extract-check` could never see this — it
compares against the sibling's *working tree*, where the bytes match. A gate
that only ever runs where somebody's uncommitted work lives is a gate that
cannot report this.

**Resolution: recorded, with an expiry date.** The four paths are `DIVERGED`
in `bin/build/kernel-divergence.txt` under a section that states the expiry:
delete it once upstream commits them, because after that the two sides are
identical and a `DIVERGED` line would only hide later drift in four theme
files. The section also carries the reproduction — `git clone --depth 1` of
upstream, then `--check --minimal-root` at the clone — because that comparison
is the only one that shows them. Counts with the section in place: **246
identical / 42 recorded** (was 250/38).

## Verified

| check | result |
|---|---|
| guard against a fresh clone of upstream, four entries recorded | `OK (246 identical, 42 recorded divergences)`, exit **0** |
| the same run with those four lines removed | exit **1**, `4 unrecorded difference(s)` — the entries are load-bearing, and the finding reproduces without CI |
| `make extract-check` (sibling working tree) | `OK (246 identical, 42 recorded)`, exit **0** |
| the job's `NOT RUN` step, extracted from the workflow and run | exit **0**, prints the private-repo reason and the way to enable it |
| the job's steps | `Checkout code` (this repo) + that step; no second repository, no `secrets.` reference anywhere in the workflow |
| anonymised upstream access | `Repository not found` / API 404 without a token; 200 with the owner's, `"private": true` |

The planted-edit negative control from the previous version of this section no
longer applies to CI (there is no guard step there any more); it still holds for
the clone-based run above.

