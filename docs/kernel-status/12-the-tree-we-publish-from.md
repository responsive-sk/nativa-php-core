# Part 12 > the tree we publish from

**The export round.** The request was one sentence: a new repository that
carries only the kernel, so a site has a starting point that is not this
experiment. The answer was not a second hand-maintained tree. Two trees edited
by hand drift on the first commit, and the drift is invisible in the one with
fewer gates. `nativa-core` is generated from a commit of this tree, carries a
marker naming that commit, and is replaced wholesale by the next export.

## What was read first

| read | what it settled |
|---|---|
| `git ls-files` per top directory | 2 115 tracked files: 505 under `modules/`, 310 under `Frontend/`, 79 under `lekalo/`, 28 under `docs/`, 7 under `openspec/` |
| `php bin/cms module:list` | 26 catalogued modules, 5 of them themes under `modules/Themes/` |
| `bin/build/build-dist.sh` | the artifact already decides what a deployment needs; the export is a SOURCE tree, so it keeps the catalogue and the Frontend sources the artifact drops |
| `tests/Architecture/ToolchainRegistryContractTest.php` | a new Make target is a registry row, and a new gate must name the job it runs in |
| `.github/workflows/ci.yml` | the tests job already installs Composer, so the export check joins it rather than adding a sixteenth job |

## What the export carries

`bin/export/core/prune.txt` is the decision, one path and one reason per line.
Measured on the first export: **30 paths pruned**, **1 047 files** in the
tree.

| pruned | why |
|---|---|
| `docs/`, `storage/blog`, `storage/blog-images`, `storage/pages` | the boot log, the approval ledgers and the article corpus belong to this tree |
| `lekalo/`, `lekalo.lock`, `openspec/` | the model and the plans; no exported target reads them |
| `.ai-factory/`, `.codex/`, `AGENTS.md`, `CONTRIBUTING.md` | agent tooling and this tree's rules; the export ships its own short `AGENTS.md` |
| `Makefile`, `toolchain.json`, `phpunit.xml`, `.gitignore`, `README.md`, `.github/workflows/ci.yml` | each names something the export does not have, so each is replaced by `bin/export/core/<same name>` |
| `bin/build/{extract-kernel,sync-themes,kernel-size,render-theme-pages,lekalo-modules,mago-modules-report,export-core,publish-core}.sh`, `bin/build/kernel-divergence.txt`, `bin/ops/{lekalo-reports,export-core-registry,export-core-verify}.php` | they keep this tree in step with a sibling checkout, measure this tree, or generate and publish the export itself |

Everything else travels whole: `src/` (Core, Database, Tooling, KernelTheme,
`src/Themes/kernel`), `modules/`, `Frontend/packages/`, `public/` (including the
committed frozen bundles `griffe` and `mono`), `config/`, `bin/cms`,
`deploy/`, `fixtures/`, `tests/`, the dev tool configs and `composer.lock`.

## How the tree is assembled

`bin/build/export-core.sh` reads `git archive HEAD`, never the working tree. The
profile travels inside the archive, so editing it is a tracked change like any
other and an uncommitted edit cannot leak into a public repository.

Two files are generated rather than copied:

- `toolchain.json`, by `bin/ops/export-core-registry.php`, filtered to the
  exported Makefile's targets. A tool survives only when its pin needles still
  resolve in the export. Measured: **18 gates, 1 tool (mago)**; `lekalo` and
  `clickhouse` dropped with the targets that used them.
- `phpunit.xml`, from the source one plus the exclusions in
  `bin/export/core/phpunit.exclude`: **13 test files** whose subject is this
  repository's development rather than the tree. The exporter asserts every
  entry exists, ends in `Test.php`, and is absent from the exported run.

`bin/ops/export-core-verify.php` then checks the shape: required paths present,
pruned paths absent, registry and Makefile in agreement, every recipe path on
disk, no exported surface naming a pruned thing, and the marker well formed.
`composer.lock` needs one careful edit: Composer hashes `name` into the
lockfile's `content-hash`, so the export recomputes that hash with the same
algorithm (`md5(JsonFile::encode($relevant, 0))`) and `composer validate`
accepts the rename without a network round trip.

## What the first export measured

| measured | value |
|---|---|
| files in the tree | 1 047 (no vendor, no node_modules, no `dist/`) |
| size on disk | 9,3M, the tree the dry run would push |
| pruned paths | 30 |
| exported Make targets | 18, all present in the source Makefile |
| exported CI jobs | 9, every `make` call an exported target |
| excluded tests | 13 |
| exported PHPUnit suite | 976 tests discovered, 835 assertions, exit 0 |
| skipped in that run | 787, because no module is installed in a fresh export |
| `composer validate` | valid, lock hash included |
| `php bin/cms module:list` | 26 catalogued modules, 5 themes |

The preflight the dry run stands on: `gh auth status` names `evan70`, that
account is `admin` of `responsive-sk`, and `gh repo view
responsive-sk/nativa-core` reports the name does not exist yet. So the first
`--yes` takes the create branch rather than the replace branch.

The 787 skips are the export's shape, not a defect: the catalogue is a
catalogue, `src/Modules/<Name>` is gitignored, and a module test skips when its
module is absent. The exported CI's `modules` job installs the catalogue and
runs the same suite with those tests live.

## The minimal round (2026-11-09)

The first export carried the whole catalogue, so it answered "does the exporter
work", not "what does the kernel need". The second round removed the catalogue
on purpose and added back only what a surface proved.

| measured | first export | minimal export |
|---|---|---|
| files in the tree | 1 047 | **258** |
| pruned paths | 30 | **68** |
| catalogue | 26 modules, 5 themes | **2 modules, 1 theme** (`Settings`, `Themes/Nativa`) |
| excluded tests | 13 | **27** |
| exported suite | 976 tests, 787 skips | **199 tests, 52 skips** without modules |
| same suite with `module:add` | not measured | **199 tests, 0 skips** |
| `curl /` | kernel theme | **200, 19 381 B, 16 `na-card`** |
| CI | 9/9 | **9/9** (run 42597299304) |

The register behind the two catalogue entries was measured, not argued:
`bin/lab/core-surfaces.sh` builds the export-shaped tree once per dependency
surface and prints what each public path answers (`bin/lab/core.env` carries the
runtime flags; a `.env` cannot install a module).

| surface | modules | cost | verdict |
|---|---|---|---|
| Nativa on `/` | `Settings` | +14 files | kept: the theme vote is a kernel port and the kernel ships no provider |
| Slovak copy | `Settings`, `Locales` | +143 files | not shipped: English kernel copy, and `Locales` drags in a `Cardboard` whose `/mark` answers 500 without `Users` |
| `/blog`, `/cookies` | `Blog`, `Pages` | +373 files | not shipped: content is not a dependency of the theme, and the corpora are pruned |
| `/mark` | `Users`, `Cardboard` | +209 files | not shipped: the panel is not part of the starting point |

The second round also moved three things the first round assumed:

- `check-theme-bundles.sh` reads `modules/Themes/` instead of a hardcoded list
  of six names, so the same script is correct in both trees (measured:
  `themes=6` here, `themes=1` in the export).
- The export carries its own `config/deploy.php` (`theme => nativa`); the source
  profile names `boson`, which the export does not have. `dist-check` reports
  "kernel defaults (no Settings module to apply config/deploy.php)", which is
  the honest state of an artifact built without a catalogue.
- The exporter's test floor moved from 800 to 150. It guards against an
  exclusion list that swallows the tree, and the minimal tree's honest count is
  199; the old floor would have been a target to pad.

## The gate

`make core-export-check` runs the whole thing: assemble, lint, `composer
validate`, and the exported suite in a temporary directory. It copies this
tree's `vendor/` rather than symlinking it, because PHP resolves `__FILE__`
through a symlink and Composer's `$baseDir` would point back at the source; the
suite would then load the source classes and prove nothing.

The check runs as a step of the existing `tests` CI job, so the job count does
not grow. `make core-export` writes a tree (`CORE_TO=<dir>`,
`CORE_EXPORT_ARGS=--ref <commit>`), and `make publish-core` replaces the public
repository with `--dry-run` printing what it would push.

## What this round did not close

- The first public push was a separate, explicit step. It ran once with
  `make publish-core PUBLISH_ARGS=--yes` (source `b7533a2`, 258 files) and the
  published CI answered 9/9; the marker names the source commit, so the history
  of the public tree stays a list of exports.
- `/docs` in the export answers 200 with an empty index. The guides are
  written for this tree and name `openspec/`, `lekalo/` and the boot log, so
  rewriting them for the export is a documentation round of its own. The
  exported `README.md` is the operator guide until then.
- The 13 excluded tests are a smaller gate surface in the public tree. They
  are all measurement-of-this-repository checks, and the source tree runs them
  on every commit; the export is regenerated from that commit. Recorded rather
  than implied.
- The exported theme modules are a copy. A theme change lands in
  `nativa-themes`, then here, then in the export, and the exported README says
  so.
