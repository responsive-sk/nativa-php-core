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
and the measured state of THIS tree is `docs/kernel-status.md`.

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
started server: `/`, `/robots.txt`, `/sitemap.xml` and `/docs` answer **200**,
`/nope` answers **404**, and a module installed over HTTP answers `200` →
`404` across `module:add` / `module:remove`.

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

## Build a deployable tree

```sh
make assets                    # theme bundles → public/dist/ (gitignored)
bin/build/build-dist.sh        # → dist/ : assets, migrated databases, smoke-tested
```

`dist/` carries no `vendor/`, no `Frontend/` sources, no dev configs and **one**
`Modules` tree — the installed copies, not a second copy of the catalogue
(`--with-catalogue` ships that too, for a target that must install modules
itself). It keeps `bin/cms`: that is the production tool for `migrate` and
`module:remove`, and it is plain PHP by design. The script migrates the artifact
in place and then boots it over HTTP, so a broken build fails where it is built
rather than on the server.
