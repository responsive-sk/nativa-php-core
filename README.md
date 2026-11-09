# Nativa PHP Core

The **kernel-only** tree. Two modules travel whole — `Modules\Core` and
`Modules\Database` — plus one theme. Everything else is a module: copied in from
`modules/` with `bin/cms module:add`, deleted with `bin/cms module:remove`, and
provably gone afterwards.

This tree exists to test that claim. The showcase (`../nativa-minimal`) is a full
site; a site cannot demonstrate that something is removable, because the thing it
would demonstrate on is always installed.

## Where this came from

The kernel slice was imported verbatim from `../nativa-minimal` on 2026-10-11:
`src/Modules/Core` (65 PHP files, 8 194 lines) and `src/Modules/Database` (2 files,
372 lines). Nothing in the slice was edited on import — an import that starts
"fixing things" is a fork, and a fork cannot tell you what the original did.

That import carries the showcase's coupling with it: **34 `use Modules\<X>\…`
statements across 14 files, naming 9 modules** (Blog, Cardboard, Locales, Media,
Outbox, Pages, Security, Settings, Users). The ledger — every edge, with the
answer it gets (`M`ove / `C`ontract / `G`uard) — is `../nativa-minimal`'s
`tests/Architecture/KernelCouplingTest.php` + `tests/Support/KernelCoupling.php`,
and the measured state of THIS tree is `docs/kernel-status.md` — the index
over the per-round boot log kept in `docs/kernel-status/`.

## The boundary rule

> A kernel file may not import a module. A module may import the kernel.

A surface that needs a module is a surface that belongs to a module. The kernel
owns: routing, the request/response pair, the middleware pipeline and its
discovery, the migrator runner, the SQLite connection manager, the asset
resolver, markdown rendering, the error pages and the SEO text surfaces
(`/robots.txt`, `/sitemap.xml`) — degraded to what they can answer with nothing
installed.

## What it does today

`docs/kernel-status.md` records the measured state, with the status codes read
back off this tree rather than paraphrased from a plan. Measured on a freshly
started server: `/`, `/robots.txt` and `/sitemap.xml` answer **200**, `/docs`
and `/nope` answer **404** because Docs is a module, and a module installed
over HTTP answers `200` → `404` across `module:add` / `module:remove`.

`/` was a 500 for most of this kernel's life — `HomepageAction` reached for a
module that is not part of it. Boot 19 closed that, and the same boot found the
worst defect the tree had: `Router` discovery made each class's **parent** a
load-time dependency of routing, so a checkout with no `vendor/` answered 500 to
every request because three of Core's own classes extend `symfony/console`. The
repo's own [`docs/kernel-status.md`](docs/kernel-status.md) has the measurement
and the fix.

The shell port (`ShellContextProvider`, discovered by `#[AsShellContextProvider]`)
is the first of the 34 edges cut: the site name, the active theme, the default
locale, the language list and the navigation menu now come from a kernel-owned
port a module implements — and **absence is a first-class answer**, so this tree
renders with no provider installed at all.

## Run it

```sh
php -S 127.0.0.1:8000 -t public    # no vendor/ needed — the runtime is the point
```

`composer install` is for development only (phpstan, rector, php-cs-fixer,
PHPUnit). It is not a prerequisite: `src/init.php` installs a PSR-4 fallback
loader for the tree that has none, and the CI job called "Boot (kernel-only, no
vendor)" exists precisely to keep that claim honest.

The first request (or `php bin/cms migrate`) builds the databases EMPTY, so a
fresh tree has no account and `/mark` answers 403. Create the first
administrator — the admin role and the five permissions with it — in one line:

```sh
php bin/cms user:admin                                  # admin@example.test / admin1234
php bin/cms user:admin you@example.com 'your-password'  # your own credentials
```

Commit messages carry no agent footer (the nativa-minimal convention) —
after cloning, enable the enforcer: `cp bin/git-hooks/commit-msg .git/hooks/commit-msg`.

## Build a deployable tree

```sh
make assets                    # theme bundles → public/dist/ (gitignored)
bin/build/build-dist.sh        # → dist/ : assets, migrated databases, smoke-tested
make dist-check                # same build, but from a clean clone of HEAD
```

`make dist-check` is the gate CI runs: it clones `HEAD` into a temp directory —
tracked bytes only, no `vendor/`, no locally installed module slices, no
uncommitted edits — builds the artifact there, then asserts the smoke test
passed, the artifact carries no `vendor/`, and `.build-info` agrees. A green
local `dist/` that a clone cannot reproduce fails there. `make dist-profile-test`
covers the three identity branches — Settings absent, `--no-profile`, and a
profile that is wrong while Settings IS installed (that one must fail the
build) — each in its own clone, with no HTTP smoke run.

`dist/` carries no `vendor/`, no `Frontend/` sources, no dev configs, no
`fixtures/` (the theme-gallery inputs a build-time script reads) and **one**
`Modules` tree — the installed copies, not a second copy of the catalogue
(`--with-catalogue` ships that too, for a target that must install modules
itself). It keeps `bin/cms`: that is the production tool for `migrate` and
`module:remove`, and it is plain PHP by design. The script migrates the artifact
in place and then boots it over HTTP, so a broken build fails where it is built
rather than on the server.

The artifact's identity comes from `config/deploy.php` — the settings a build
STARTS as (`theme`, `site_name`, …), applied by `bin/cms settings:profile` right
after `migrate`. Migrating alone seeds the kernel defaults and copies none of
the developer's `storage/data/`, so without the profile a rebuilt responsive.sk
dist came up as the kernel's own site (the theme's copy looked deleted). Build
with `--no-profile` to ship the kernel defaults on purpose; afterwards every
value is still the operator's to change in `/mark` → Settings. A kernel-only
checkout has the profile file but no `Settings` module to apply it with, so that
build keeps the kernel defaults and says so — the failure that would matter
(the module is installed, the profile is wrong) still fails the build.

## The published tree

`responsive-sk/nativa-core` is **generated** from a commit of this tree: the
kernel, the module catalogue and the Frontend sources, without the boot log,
the article corpus, the Lekalo model or the plans. It is a starting point for a
site, not a second source. A change is made here and exported:

```sh
make core-export-check                     # the gate: assemble, lint, validate, run its suite
make core-export CORE_TO=../nativa-core    # write the tree from HEAD
make publish-core PUBLISH_ARGS=--dry-run   # what a publish would push
```

The profile is `bin/export/core/`: the prune list with a reason per path, the
Makefile and CI the export ships, the PHPUnit exclusions, and the README and
`AGENTS.md` the public tree carries.
`docs/kernel-status/12-the-tree-we-publish-from.md` records the measured export
and the three things this round did not close.
